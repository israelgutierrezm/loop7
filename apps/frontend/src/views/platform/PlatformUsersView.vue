<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useDebounceFn } from '@vueuse/core'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import { useConfirmStore } from '@/stores/confirm'
import { apiErrorMessage } from '@/utils/errors'
import { date, dateTime, relativeTime } from '@/utils/format'
import PageHeader from '@/components/ui/PageHeader.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'

interface PlatformUser {
  id: string
  name: string
  email: string
  is_platform_admin: boolean
  two_factor_enabled: boolean
  email_verified: boolean
  blocked: boolean
  blocked_at: string | null
  organizations_count: number | null
  last_login_at: string | null
  created_at: string | null
}

const auth = useAuthStore()
const router = useRouter()
const toasts = useToastStore()
const confirmDialog = useConfirmStore()

const users = ref<PlatformUser[]>([])
const loading = ref(true)
const failed = ref(false)
const search = ref('')
const onlyBlocked = ref(false)
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)
const busyId = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const { data } = await http.get('/platform/users', {
      params: { q: search.value.trim() || undefined, blocked: onlyBlocked.value ? 1 : undefined, page: page.value },
    })
    users.value = data.data
    lastPage.value = data.meta?.last_page ?? 1
    total.value = data.meta?.total ?? users.value.length
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

function reload(): void {
  page.value = 1
  load()
}

const debouncedReload = useDebounceFn(reload, 350)
watch(search, () => debouncedReload())
watch(onlyBlocked, reload)

function goTo(target: number): void {
  page.value = target
  load()
}

/** Otros administradores y la propia cuenta no se gestionan desde aquí. */
function manageable(user: PlatformUser): boolean {
  return !user.is_platform_admin && user.id !== auth.user?.id
}

function replace(updated: PlatformUser): void {
  if (onlyBlocked.value && !updated.blocked) {
    users.value = users.value.filter((u) => u.id !== updated.id)
    total.value = Math.max(0, total.value - 1)
    return
  }
  users.value = users.value.map((u) => (u.id === updated.id ? updated : u))
}

async function act(user: PlatformUser, action: 'block' | 'unblock' | 'reset-two-factor'): Promise<void> {
  const dialogs = {
    block: {
      title: `Bloquear a ${user.name}`,
      message: 'No podrá iniciar sesión y perderá la sesión abierta en su siguiente acción. Sus organizaciones siguen funcionando para el resto del equipo. Queda auditado.',
      confirmText: 'Bloquear cuenta',
      danger: true,
    },
    unblock: {
      title: `Desbloquear a ${user.name}`,
      message: 'Podrá volver a iniciar sesión con su contraseña.',
      confirmText: 'Desbloquear',
      danger: false,
    },
    'reset-two-factor': {
      title: `Restablecer el doble factor de ${user.name}`,
      message: 'Se desactiva su verificación en dos pasos para que entre sólo con su contraseña y vuelva a activarla. Hazlo únicamente tras verificar su identidad por otro medio.',
      confirmText: 'Restablecer',
      danger: true,
    },
  } as const

  if (!(await confirmDialog.ask({ ...dialogs[action] }))) return

  busyId.value = user.id
  try {
    const { data } = await http.post(`/platform/users/${user.id}/${action}`)
    replace(data.data)
    toasts.success(data.message ?? 'Hecho.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busyId.value = null
  }
}

async function impersonate(user: PlatformUser): Promise<void> {
  const ok = await confirmDialog.ask({
    title: `Entrar como ${user.name}`,
    message: 'Verás la aplicación como esta persona durante un máximo de 60 minutos. Las acciones sensibles están bloqueadas y todo queda auditado.',
    confirmText: 'Impersonar',
  })
  if (!ok) return

  busyId.value = user.id
  try {
    await http.post(`/platform/impersonate/${user.id}`)
    await auth.fetchMe()
    toasts.success(`Estás viendo la aplicación como ${user.name}.`)
    router.push('/app')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busyId.value = null
  }
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader
      title="Usuarios"
      description="Cuentas de la plataforma. Bloquea por abuso, restablece el doble factor a quien perdió su dispositivo o entra como la persona para darle soporte. Todo queda auditado."
    />

    <div class="mb-4 flex flex-wrap items-center gap-3">
      <label for="user-search" class="sr-only">Buscar</label>
      <input id="user-search" v-model="search" type="search" placeholder="Buscar por nombre o correo…" class="input w-72" />
      <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
        <input v-model="onlyBlocked" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500" />
        Sólo bloqueados
      </label>
      <span v-if="!loading && !failed" class="ml-auto text-xs text-slate-400">{{ total }} {{ total === 1 ? 'usuario' : 'usuarios' }}</span>
    </div>

    <div v-if="loading" class="card p-6"><div v-for="n in 6" :key="n" class="skeleton my-2 h-8 w-full" /></div>
    <ErrorState v-else-if="failed" @retry="load" />
    <EmptyState
      v-else-if="users.length === 0"
      icon="team"
      :title="onlyBlocked ? 'No hay cuentas bloqueadas' : 'Sin resultados'"
      :description="search ? 'Prueba con otro nombre o correo.' : undefined"
    />

    <div v-else class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
          <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50">
            <tr>
              <th scope="col" class="px-5 py-3 font-medium">Usuario</th>
              <th scope="col" class="px-5 py-3 font-medium">Estado</th>
              <th scope="col" class="px-5 py-3 font-medium">Orgs</th>
              <th scope="col" class="px-5 py-3 font-medium">Doble factor</th>
              <th scope="col" class="px-5 py-3 font-medium">Último acceso</th>
              <th scope="col" class="px-5 py-3"><span class="sr-only">Acciones</span></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
            <tr v-for="user in users" :key="user.id" class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
              <td class="px-5 py-3">
                <p class="flex flex-wrap items-center gap-2 font-medium text-slate-900 dark:text-white">
                  {{ user.name }}
                  <StatusBadge v-if="user.is_platform_admin" tone="danger">Admin</StatusBadge>
                  <StatusBadge v-if="user.id === auth.user?.id" tone="info">Tú</StatusBadge>
                </p>
                <p class="text-xs text-slate-400">
                  {{ user.email }}
                  <span v-if="!user.email_verified" class="text-amber-600"> · sin verificar</span>
                </p>
              </td>
              <td class="px-5 py-3">
                <StatusBadge v-if="user.blocked" tone="danger" dot :title="`Bloqueada el ${dateTime(user.blocked_at)}`">Bloqueada</StatusBadge>
                <StatusBadge v-else tone="success" dot>Activa</StatusBadge>
              </td>
              <td class="px-5 py-3 text-slate-500">{{ user.organizations_count ?? 0 }}</td>
              <td class="px-5 py-3">
                <StatusBadge :tone="user.two_factor_enabled ? 'success' : 'neutral'">{{ user.two_factor_enabled ? 'Activo' : 'No' }}</StatusBadge>
              </td>
              <td class="px-5 py-3 text-slate-500" :title="user.last_login_at ? dateTime(user.last_login_at) : undefined">
                {{ user.last_login_at ? relativeTime(user.last_login_at) : 'Nunca' }}
                <p class="text-xs text-slate-400">Alta {{ date(user.created_at) }}</p>
              </td>
              <td class="px-5 py-3">
                <div v-if="manageable(user)" class="flex flex-wrap justify-end gap-1">
                  <button
                    v-if="!user.blocked"
                    type="button"
                    class="btn-ghost px-3 py-1 text-xs"
                    :disabled="busyId === user.id"
                    @click="impersonate(user)"
                  >
                    Impersonar
                  </button>
                  <button
                    v-if="user.two_factor_enabled"
                    type="button"
                    class="btn-ghost px-3 py-1 text-xs"
                    :disabled="busyId === user.id"
                    @click="act(user, 'reset-two-factor')"
                  >
                    Restablecer MFA
                  </button>
                  <button
                    v-if="user.blocked"
                    type="button"
                    class="btn-ghost px-3 py-1 text-xs"
                    :disabled="busyId === user.id"
                    @click="act(user, 'unblock')"
                  >
                    Desbloquear
                  </button>
                  <button
                    v-else
                    type="button"
                    class="btn-ghost px-3 py-1 text-xs text-rose-600 hover:text-rose-700 dark:text-rose-400"
                    :disabled="busyId === user.id"
                    @click="act(user, 'block')"
                  >
                    Bloquear
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="lastPage > 1" class="flex items-center justify-end gap-2 border-t border-slate-100 px-5 py-3 text-sm dark:border-slate-800">
        <button type="button" class="btn-ghost px-3 py-1 text-xs" :disabled="page <= 1" @click="goTo(page - 1)">Anterior</button>
        <span class="text-slate-500">Página {{ page }} de {{ lastPage }}</span>
        <button type="button" class="btn-ghost px-3 py-1 text-xs" :disabled="page >= lastPage" @click="goTo(page + 1)">Siguiente</button>
      </div>
    </div>
  </div>
</template>
