<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import { useConfirmStore } from '@/stores/confirm'
import type { Invitation, MemberEntry } from '@/types/models'
import { apiErrorMessage, apiValidationErrors } from '@/utils/errors'
import { date } from '@/utils/format'
import PageHeader from '@/components/ui/PageHeader.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import ModalDialog from '@/components/ui/ModalDialog.vue'
import AppIcon from '@/components/AppIcon.vue'
import Spinner from '@/components/ui/Spinner.vue'

interface RoleOption {
  value: string
  label: string
}

const auth = useAuthStore()
const toasts = useToastStore()
const confirmDialog = useConfirmStore()

const members = ref<MemberEntry[]>([])
const invitations = ref<Invitation[]>([])
const roles = ref<RoleOption[]>([])
const assignable = ref<string[]>([])
const loading = ref(true)
const failed = ref(false)

const showInvite = ref(false)
const inviting = ref(false)
const inviteForm = reactive({ email: '', role: 'VIEWER' })
const inviteErrors = ref<Record<string, string[]>>({})

// Acceso por marca
const canManageBrandAccess = auth.can('brands.manage_access')
const editingAccess = ref<MemberEntry | null>(null)
const accessForm = reactive({ all: true, brands: [] as string[] })
const savingAccess = ref(false)

const roleLabels = computed(() => Object.fromEntries(roles.value.map((r) => [r.value, r.label])))
const assignableOptions = computed(() => roles.value.filter((r) => assignable.value.includes(r.value)))
const pendingInvitations = computed(() =>
  invitations.value.filter((i) => i.status === 'pending' && (!i.expires_at || new Date(i.expires_at) > new Date())),
)
const brandNames = computed(() => Object.fromEntries(auth.brands.map((b) => [b.id, b.name])))

function isSelf(member: MemberEntry): boolean {
  return member.user.id === auth.user?.id
}

/** ¿Puede cambiar el rol de este miembro? (no a sí mismo, ni al propietario, ni a un rol que no puede asignar). */
function canEditRole(member: MemberEntry): boolean {
  return auth.can('roles.assign')
    && !member.membership.is_owner
    && !isSelf(member)
    && member.roles.every((r) => assignable.value.includes(r))
}

function canEditAccess(member: MemberEntry): boolean {
  return canManageBrandAccess && !member.membership.is_owner && !isSelf(member)
}

/** Suspender exige el mismo poder que quitar y la misma jerarquía que los roles. */
function canSuspend(member: MemberEntry): boolean {
  return auth.can('members.remove')
    && !member.membership.is_owner
    && !isSelf(member)
    && member.roles.every((r) => assignable.value.includes(r))
}

async function toggleSuspension(member: MemberEntry): Promise<void> {
  const suspend = member.membership.status !== 'suspended'
  if (suspend) {
    const ok = await confirmDialog.ask({
      title: `Suspender a ${member.user.name}`,
      message: 'Perderá el acceso a la organización hasta que lo reactives. Conserva su rol y sus marcas, y deja de recibir avisos.',
      confirmText: 'Suspender acceso',
      danger: true,
    })
    if (!ok) return
  }
  try {
    const { data } = await http.patch(`/organization/members/${member.user.id}`, { status: suspend ? 'suspended' : 'active' })
    Object.assign(member.membership, data.data.membership)
    toasts.success(suspend ? `${member.user.name} ya no tiene acceso.` : `${member.user.name} vuelve a tener acceso.`)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

function accessSummary(member: MemberEntry): string {
  if (member.membership.is_owner || member.membership.all_brands_access) return 'Todas las marcas'
  const names = member.membership.brands.map((id) => brandNames.value[id]).filter(Boolean)
  const hidden = member.membership.brands.length - names.length
  if (member.membership.brands.length === 0) return 'Sin marcas asignadas'
  return names.join(', ') + (hidden > 0 ? ` y ${hidden} más` : '')
}

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const [m, r] = await Promise.all([http.get('/organization/members'), http.get('/roles')])
    members.value = m.data.data
    roles.value = r.data.data.roles.filter((role: RoleOption) => role.value !== 'OWNER')
    assignable.value = r.data.data.assignable ?? []
    if (!assignable.value.includes(inviteForm.role)) inviteForm.role = assignable.value.at(-1) ?? 'VIEWER'
    if (auth.can('members.view')) {
      const inv = await http.get('/organization/invitations')
      invitations.value = inv.data.data
    }
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

async function invite(): Promise<void> {
  inviting.value = true
  inviteErrors.value = {}
  try {
    const { data } = await http.post('/organization/invitations', { ...inviteForm })
    invitations.value.unshift(data.data)
    showInvite.value = false
    inviteForm.email = ''
    toasts.success('Invitación enviada.')
  } catch (e) {
    inviteErrors.value = apiValidationErrors(e)
    if (!Object.keys(inviteErrors.value).length) toasts.error(apiErrorMessage(e))
  } finally {
    inviting.value = false
  }
}

async function changeRole(member: MemberEntry, role: string, select: HTMLSelectElement): Promise<void> {
  try {
    const { data } = await http.patch(`/organization/members/${member.user.id}`, { role })
    member.roles = data.data.roles
    toasts.success(`Rol de ${member.user.name}: ${roleLabels.value[role] ?? role}.`)
  } catch (e) {
    select.value = member.roles[0] ?? ''
    toasts.error(apiErrorMessage(e))
  }
}

function openAccess(member: MemberEntry): void {
  editingAccess.value = member
  accessForm.all = member.membership.all_brands_access
  accessForm.brands = [...member.membership.brands]
}

async function saveAccess(): Promise<void> {
  const member = editingAccess.value
  if (!member) return
  if (!accessForm.all && accessForm.brands.length === 0) {
    toasts.error('Elige al menos una marca o da acceso a todas.')
    return
  }
  savingAccess.value = true
  try {
    const { data } = await http.patch(`/organization/members/${member.user.id}`, {
      all_brands_access: accessForm.all,
      brands: accessForm.all ? member.membership.brands : accessForm.brands,
    })
    Object.assign(member.membership, data.data.membership)
    editingAccess.value = null
    toasts.success('Acceso a marcas actualizado.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    savingAccess.value = false
  }
}

async function removeMember(member: MemberEntry): Promise<void> {
  const ok = await confirmDialog.ask({ title: 'Quitar miembro', message: `${member.user.name} perderá el acceso a la organización.`, confirmText: 'Quitar', danger: true })
  if (!ok) return
  try {
    await http.delete(`/organization/members/${member.user.id}`)
    members.value = members.value.filter((m) => m.user.id !== member.user.id)
    toasts.success('Miembro eliminado.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function revokeInvitation(inv: Invitation): Promise<void> {
  try {
    await http.delete(`/organization/invitations/${inv.id}`)
    invitations.value = invitations.value.filter((i) => i.id !== inv.id)
    toasts.success('Invitación revocada.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader title="Equipo" description="Miembros, roles, acceso a marcas e invitaciones de tu organización.">
      <template #actions>
        <RouterLink v-if="auth.can('roles.view')" to="/app/team/roles" class="btn-secondary">
          <AppIcon name="lock" :size="18" /> Roles y permisos
        </RouterLink>
        <button v-if="auth.can('members.invite')" class="btn-primary" @click="showInvite = true">
          <AppIcon name="plus" :size="18" /> Invitar
        </button>
      </template>
    </PageHeader>

    <div v-if="loading" class="card p-6">
      <div v-for="n in 4" :key="n" class="flex items-center gap-4 py-3">
        <div class="skeleton h-10 w-10 rounded-full" />
        <div class="flex-1"><div class="skeleton h-4 w-1/3" /><div class="skeleton mt-2 h-3 w-1/4" /></div>
      </div>
    </div>

    <ErrorState v-else-if="failed" @retry="load" />

    <template v-else>
      <!-- Miembros -->
      <div class="card overflow-hidden">
        <div class="border-b border-slate-100 px-5 py-3 dark:border-slate-800">
          <h2 class="font-semibold text-slate-900 dark:text-white">Miembros ({{ members.length }})</h2>
        </div>
        <ul class="divide-y divide-slate-100 dark:divide-slate-800">
          <li v-for="member in members" :key="member.user.id" class="flex flex-wrap items-center gap-3 px-5 py-4">
            <span
              class="grid h-10 w-10 shrink-0 place-items-center rounded-full text-sm font-bold text-white"
              :class="member.membership.status === 'suspended' ? 'bg-slate-400 dark:bg-slate-600' : 'bg-brand-600'"
              aria-hidden="true"
            >
              {{ member.user.name.charAt(0).toUpperCase() }}
            </span>
            <div class="min-w-0 flex-1">
              <p class="flex flex-wrap items-center gap-2 font-medium text-slate-900 dark:text-white">
                {{ member.user.name }}
                <span v-if="member.membership.is_owner" class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-amber-700">
                  Propietario
                </span>
                <span v-if="member.membership.status === 'suspended'" class="rounded-full bg-rose-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-rose-700 dark:bg-rose-950/50 dark:text-rose-300">
                  Suspendido
                </span>
                <span v-if="isSelf(member)" class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-slate-500 dark:bg-slate-800">Tú</span>
              </p>
              <p class="truncate text-sm text-slate-400">{{ member.user.email }}</p>
              <p class="mt-0.5 flex items-center gap-1 truncate text-xs text-slate-500">
                <AppIcon name="brands" :size="12" /> {{ accessSummary(member) }}
                <button
                  v-if="canEditAccess(member)"
                  class="ml-1 font-medium text-brand-600 hover:underline dark:text-brand-400"
                  :aria-label="`Editar acceso a marcas de ${member.user.name}`"
                  @click="openAccess(member)"
                >
                  Editar
                </button>
              </p>
            </div>

            <label v-if="canEditRole(member)" class="sr-only" :for="`role-${member.user.id}`">Rol de {{ member.user.name }}</label>
            <select
              v-if="canEditRole(member)"
              :id="`role-${member.user.id}`"
              class="input w-auto py-1.5 text-sm"
              :value="member.roles[0] ?? ''"
              @change="changeRole(member, ($event.target as HTMLSelectElement).value, $event.target as HTMLSelectElement)"
            >
              <option v-for="r in assignableOptions" :key="r.value" :value="r.value">{{ r.label }}</option>
            </select>
            <span v-else class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">
              {{ member.membership.is_owner ? 'Propietario' : (roleLabels[member.roles[0] ?? ''] ?? member.roles[0] ?? '—') }}
            </span>

            <button
              v-if="canSuspend(member)"
              type="button"
              class="btn-ghost px-2.5 py-1 text-xs"
              :aria-label="`${member.membership.status === 'suspended' ? 'Reactivar' : 'Suspender'} el acceso de ${member.user.name}`"
              @click="toggleSuspension(member)"
            >
              {{ member.membership.status === 'suspended' ? 'Reactivar' : 'Suspender' }}
            </button>
            <button
              v-if="auth.can('members.remove') && !member.membership.is_owner && !isSelf(member)"
              class="btn-ghost px-2 text-rose-600"
              :aria-label="`Quitar a ${member.user.name}`"
              @click="removeMember(member)"
            >
              <AppIcon name="close" :size="18" />
            </button>
          </li>
        </ul>
      </div>

      <!-- Invitaciones -->
      <div v-if="pendingInvitations.length" class="card mt-6 overflow-hidden">
        <div class="border-b border-slate-100 px-5 py-3 dark:border-slate-800">
          <h2 class="font-semibold text-slate-900 dark:text-white">Invitaciones pendientes ({{ pendingInvitations.length }})</h2>
        </div>
        <ul class="divide-y divide-slate-100 dark:divide-slate-800">
          <li v-for="inv in pendingInvitations" :key="inv.id" class="flex items-center gap-3 px-5 py-4">
            <AppIcon name="inbox" :size="20" class="text-slate-400" />
            <div class="min-w-0 flex-1">
              <p class="truncate font-medium text-slate-800 dark:text-slate-100">{{ inv.email }}</p>
              <p class="text-xs text-slate-400">
                {{ roleLabels[inv.role] ?? inv.role }} · caduca el {{ date(inv.expires_at) }}
              </p>
            </div>
            <button
              v-if="auth.can('members.invite')"
              class="btn-ghost text-xs text-rose-600"
              @click="revokeInvitation(inv)"
            >
              Revocar
            </button>
          </li>
        </ul>
      </div>
    </template>

    <ModalDialog :open="showInvite" title="Invitar a un miembro" @close="showInvite = false">
      <form class="space-y-4" @submit.prevent="invite">
        <div>
          <label class="label" for="i-email">Correo electrónico</label>
          <input id="i-email" v-model="inviteForm.email" type="email" required class="input" />
          <p v-if="inviteErrors.email" class="mt-1 text-xs text-rose-600">{{ inviteErrors.email[0] }}</p>
        </div>
        <div>
          <label class="label" for="i-role">Rol</label>
          <select id="i-role" v-model="inviteForm.role" class="input">
            <option v-for="r in assignableOptions" :key="r.value" :value="r.value">{{ r.label }}</option>
          </select>
          <p v-if="inviteErrors.role" class="mt-1 text-xs text-rose-600">{{ inviteErrors.role[0] }}</p>
        </div>
        <p class="text-xs text-slate-500">La persona entra con acceso a todas las marcas; puedes limitarlo después desde esta pantalla.</p>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" class="btn-secondary" @click="showInvite = false">Cancelar</button>
          <button type="submit" class="btn-primary" :disabled="inviting">
            <Spinner v-if="inviting" :size="18" /> Enviar invitación
          </button>
        </div>
      </form>
    </ModalDialog>

    <ModalDialog
      :open="editingAccess !== null"
      title="Acceso a marcas"
      :description="editingAccess ? `Qué marcas puede ver y operar ${editingAccess.user.name}.` : ''"
      @close="editingAccess = null"
    >
      <form class="space-y-4" @submit.prevent="saveAccess">
        <fieldset class="space-y-2">
          <legend class="sr-only">Alcance</legend>
          <label class="flex cursor-pointer items-start gap-2 text-sm">
            <input v-model="accessForm.all" type="radio" :value="true" class="mt-0.5 text-brand-600 focus:ring-brand-500" />
            <span><strong class="font-medium text-slate-800 dark:text-slate-100">Todas las marcas</strong><br /><span class="text-xs text-slate-500">Incluidas las que se creen en el futuro.</span></span>
          </label>
          <label class="flex cursor-pointer items-start gap-2 text-sm">
            <input v-model="accessForm.all" type="radio" :value="false" class="mt-0.5 text-brand-600 focus:ring-brand-500" />
            <span><strong class="font-medium text-slate-800 dark:text-slate-100">Solo estas marcas</strong></span>
          </label>
        </fieldset>
        <fieldset v-if="!accessForm.all" class="max-h-64 space-y-1 overflow-y-auto rounded-lg border border-slate-200 p-3 dark:border-slate-700">
          <legend class="sr-only">Marcas</legend>
          <label v-for="b in auth.brands" :key="b.id" class="flex cursor-pointer items-center gap-2 rounded px-1 py-1 text-sm hover:bg-slate-50 dark:hover:bg-slate-800">
            <input v-model="accessForm.brands" type="checkbox" :value="b.id" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500" />
            {{ b.name }}
          </label>
          <p v-if="auth.brands.length === 0" class="text-sm text-slate-500">No hay marcas que puedas gestionar.</p>
        </fieldset>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" class="btn-secondary" @click="editingAccess = null">Cancelar</button>
          <button type="submit" class="btn-primary" :disabled="savingAccess">
            <Spinner v-if="savingAccess" :size="18" /> Guardar
          </button>
        </div>
      </form>
    </ModalDialog>
  </div>
</template>
