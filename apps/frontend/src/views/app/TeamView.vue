<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import type { Invitation, MemberEntry } from '@/types/models'
import { apiErrorMessage, apiValidationErrors } from '@/utils/errors'
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

const members = ref<MemberEntry[]>([])
const invitations = ref<Invitation[]>([])
const roles = ref<RoleOption[]>([])
const loading = ref(true)
const failed = ref(false)

const showInvite = ref(false)
const inviting = ref(false)
const inviteForm = reactive({ email: '', role: 'VIEWER' })
const inviteErrors = ref<Record<string, string[]>>({})

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const [m, r] = await Promise.all([
      http.get('/organization/members'),
      http.get('/roles'),
    ])
    members.value = m.data.data
    roles.value = r.data.data.roles.filter((role: RoleOption) => role.value !== 'OWNER')
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

async function changeRole(member: MemberEntry, role: string): Promise<void> {
  try {
    await http.patch(`/organization/members/${member.user.id}`, { role })
    member.roles = [role]
    toasts.success('Rol actualizado.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function removeMember(member: MemberEntry): Promise<void> {
  if (!confirm(`¿Quitar a ${member.user.name} de la organización?`)) return
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
    <PageHeader title="Equipo" description="Miembros, roles e invitaciones de tu organización.">
      <template #actions>
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
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-brand-600 text-sm font-bold text-white">
              {{ member.user.name.charAt(0).toUpperCase() }}
            </span>
            <div class="min-w-0 flex-1">
              <p class="flex items-center gap-2 font-medium text-slate-900 dark:text-white">
                {{ member.user.name }}
                <span v-if="member.membership.is_owner" class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-amber-700">
                  Propietario
                </span>
              </p>
              <p class="truncate text-sm text-slate-400">{{ member.user.email }}</p>
            </div>

            <select
              v-if="auth.can('roles.assign') && !member.membership.is_owner"
              class="input w-auto py-1.5 text-sm"
              :value="member.roles[0] ?? ''"
              @change="changeRole(member, ($event.target as HTMLSelectElement).value)"
            >
              <option v-for="r in roles" :key="r.value" :value="r.value">{{ r.label }}</option>
            </select>
            <span v-else class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">
              {{ member.roles[0] ?? '—' }}
            </span>

            <button
              v-if="auth.can('members.remove') && !member.membership.is_owner"
              class="btn-ghost px-2 text-rose-600"
              aria-label="Eliminar miembro"
              @click="removeMember(member)"
            >
              <AppIcon name="close" :size="18" />
            </button>
          </li>
        </ul>
      </div>

      <!-- Invitaciones -->
      <div v-if="invitations.length" class="card mt-6 overflow-hidden">
        <div class="border-b border-slate-100 px-5 py-3 dark:border-slate-800">
          <h2 class="font-semibold text-slate-900 dark:text-white">Invitaciones pendientes</h2>
        </div>
        <ul class="divide-y divide-slate-100 dark:divide-slate-800">
          <li v-for="inv in invitations" :key="inv.id" class="flex items-center gap-3 px-5 py-4">
            <AppIcon name="inbox" :size="20" class="text-slate-400" />
            <div class="min-w-0 flex-1">
              <p class="truncate font-medium text-slate-800 dark:text-slate-100">{{ inv.email }}</p>
              <p class="text-xs text-slate-400">Rol: {{ inv.role }} · Estado: {{ inv.status }}</p>
            </div>
            <button
              v-if="auth.can('members.invite') && inv.status === 'pending'"
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
            <option v-for="r in roles" :key="r.value" :value="r.value">{{ r.label }}</option>
          </select>
          <p v-if="inviteErrors.role" class="mt-1 text-xs text-rose-600">{{ inviteErrors.role[0] }}</p>
        </div>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" class="btn-secondary" @click="showInvite = false">Cancelar</button>
          <button type="submit" class="btn-primary" :disabled="inviting">
            <Spinner v-if="inviting" :size="18" /> Enviar invitación
          </button>
        </div>
      </form>
    </ModalDialog>
  </div>
</template>
