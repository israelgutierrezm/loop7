<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import { useConfirmStore } from '@/stores/confirm'
import { apiErrorMessage } from '@/utils/errors'
import type { PermissionGroup, RoleDefinition } from '@/types/models'
import PageHeader from '@/components/ui/PageHeader.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import AppIcon from '@/components/AppIcon.vue'
import RoleEditorDialog from '@/components/team/RoleEditorDialog.vue'

const auth = useAuthStore()
const toasts = useToastStore()
const confirmDialog = useConfirmStore()

const roles = ref<RoleDefinition[]>([])
const groups = ref<PermissionGroup[]>([])
const customAvailable = ref(false)
const loading = ref(true)
const failed = ref(false)
const expanded = ref<string | null>(null)
const editorOpen = ref(false)
const editing = ref<RoleDefinition | null>(null)

const predefined = computed(() => roles.value.filter((r) => !r.custom))
const custom = computed(() => roles.value.filter((r) => r.custom))
const presets = computed(() => predefined.value.filter((r) => r.value !== 'OWNER'))
const myRoles = computed(() => auth.currentOrganization?.roles ?? [])

const canCreate = computed(() => auth.can('roles.create') && customAvailable.value)

/** Se gestiona un rol si no concede nada que yo no tenga y no es el mío. */
function manageable(role: RoleDefinition): boolean {
  return role.permissions.every((p) => auth.permissions.includes(p)) && !myRoles.value.includes(role.value)
}

function groupedPermissions(role: RoleDefinition): { label: string; items: string[] }[] {
  return groups.value
    .map((g) => ({ label: g.label, items: g.permissions.filter((p) => role.permissions.includes(p.key)).map((p) => p.label) }))
    .filter((g) => g.items.length > 0)
}

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const { data } = await http.get('/roles')
    roles.value = data.data.roles
    groups.value = data.data.permission_groups
    customAvailable.value = Boolean(data.data.custom_roles_available)
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

function openEditor(role: RoleDefinition | null): void {
  editing.value = role
  editorOpen.value = true
}

function saved(role: RoleDefinition): void {
  editorOpen.value = false
  const index = roles.value.findIndex((r) => r.value === role.value)
  if (index >= 0) roles.value.splice(index, 1, role)
  else roles.value.push(role)
}

async function remove(role: RoleDefinition): Promise<void> {
  const ok = await confirmDialog.ask({
    title: `Eliminar «${role.label}»`,
    message: 'Sólo se puede eliminar si nadie lo tiene asignado ni hay invitaciones pendientes con él.',
    confirmText: 'Eliminar rol',
    danger: true,
  })
  if (!ok) return
  try {
    await http.delete(`/roles/${role.value}`)
    roles.value = roles.value.filter((r) => r.value !== role.value)
    toasts.success('Rol eliminado.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader title="Roles y permisos" description="Qué puede hacer cada rol. Crea roles a la medida de tu equipo combinando permisos.">
      <template #actions>
        <RouterLink to="/app/team" class="btn-secondary text-sm"><AppIcon name="team" :size="16" /> Equipo</RouterLink>
        <button v-if="canCreate" type="button" class="btn-primary text-sm" @click="openEditor(null)">
          <AppIcon name="plus" :size="16" /> Nuevo rol
        </button>
      </template>
    </PageHeader>

    <div v-if="loading" class="card p-6"><div class="skeleton h-48 w-full" /></div>
    <ErrorState v-else-if="failed" @retry="load" />

    <template v-else>
      <section class="mb-8" aria-labelledby="custom-roles-title">
        <h2 id="custom-roles-title" class="mb-3 font-semibold text-slate-900 dark:text-white">Roles personalizados</h2>

        <div v-if="!customAvailable" class="card flex flex-wrap items-center justify-between gap-3 p-5 text-sm">
          <span class="flex items-center gap-2 text-slate-600 dark:text-slate-300">
            <AppIcon name="sparkles" :size="16" /> Disponibles en los planes Professional, Agency y Enterprise.
          </span>
          <RouterLink v-if="auth.can('billing.view')" to="/app/billing" class="btn-secondary text-sm">Ver planes</RouterLink>
        </div>

        <EmptyState
          v-else-if="custom.length === 0"
          icon="lock"
          title="Aún no hay roles personalizados"
          :description="canCreate ? 'Crea uno partiendo de un rol predefinido y ajusta sus permisos.' : 'Quien tenga permiso para crear roles puede definirlos aquí.'"
        >
          <template v-if="canCreate" #action>
            <button type="button" class="btn-primary text-sm" @click="openEditor(null)">Nuevo rol</button>
          </template>
        </EmptyState>

        <div v-else class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
          <article v-for="role in custom" :key="role.value" class="card flex flex-col p-5">
            <div class="flex items-start justify-between gap-2">
              <div class="min-w-0">
                <h3 class="truncate font-semibold text-slate-900 dark:text-white">{{ role.label }}</h3>
                <p v-if="role.description" class="mt-0.5 text-sm text-slate-500">{{ role.description }}</p>
              </div>
              <span class="shrink-0 rounded-full bg-brand-50 px-2 py-0.5 text-[10px] font-semibold uppercase text-brand-700 dark:bg-brand-950/40 dark:text-brand-300">
                Personalizado
              </span>
            </div>
            <button type="button" class="mt-3 self-start text-xs font-medium text-brand-600 hover:underline" @click="expanded = expanded === role.value ? null : role.value">
              {{ role.permissions.length }} permisos · {{ expanded === role.value ? 'ocultar' : 'ver' }}
            </button>
            <ul v-if="expanded === role.value" class="mt-2 space-y-2 text-xs text-slate-600 dark:text-slate-300">
              <li v-for="g in groupedPermissions(role)" :key="g.label">
                <span class="font-semibold">{{ g.label }}:</span> {{ g.items.join(', ') }}
              </li>
            </ul>
            <div v-if="manageable(role) && (auth.can('roles.update') || auth.can('roles.delete'))" class="mt-auto flex justify-end gap-1 pt-4">
              <button v-if="auth.can('roles.update') && customAvailable" type="button" class="btn-ghost px-3 py-1 text-xs" @click="openEditor(role)">Editar</button>
              <button v-if="auth.can('roles.delete')" type="button" class="btn-ghost px-3 py-1 text-xs text-rose-600" @click="remove(role)">Eliminar</button>
            </div>
          </article>
        </div>
      </section>

      <section aria-labelledby="predefined-roles-title">
        <h2 id="predefined-roles-title" class="mb-3 font-semibold text-slate-900 dark:text-white">Roles predefinidos</h2>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
          <article v-for="role in predefined" :key="role.value" class="card p-5">
            <h3 class="font-semibold text-slate-900 dark:text-white">{{ role.label }}</h3>
            <p class="mt-0.5 text-sm text-slate-500">{{ role.description }}</p>
            <button type="button" class="mt-3 text-xs font-medium text-brand-600 hover:underline" @click="expanded = expanded === role.value ? null : role.value">
              {{ role.permissions.length }} permisos · {{ expanded === role.value ? 'ocultar' : 'ver' }}
            </button>
            <ul v-if="expanded === role.value" class="mt-2 space-y-2 text-xs text-slate-600 dark:text-slate-300">
              <li v-for="g in groupedPermissions(role)" :key="g.label">
                <span class="font-semibold">{{ g.label }}:</span> {{ g.items.join(', ') }}
              </li>
            </ul>
          </article>
        </div>
        <p class="mt-3 text-xs text-slate-400">
          Los roles predefinidos no se modifican. Sólo se pueden asignar roles que no concedan permisos que tú no tengas.
        </p>
      </section>
    </template>

    <RoleEditorDialog
      :open="editorOpen"
      :role="editing"
      :groups="groups"
      :presets="presets"
      :my-permissions="auth.permissions"
      @close="editorOpen = false"
      @saved="saved"
    />
  </div>
</template>
