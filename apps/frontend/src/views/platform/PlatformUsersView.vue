<script setup lang="ts">
import { onMounted, ref } from 'vue'
import http from '@/services/http'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage } from '@/utils/errors'
import ErrorState from '@/components/ui/ErrorState.vue'

interface PlatformUser {
  id: string
  name: string
  email: string
  is_platform_admin: boolean
  two_factor_enabled: boolean
  email_verified: boolean
  organizations_count: number | null
  created_at: string | null
}

const auth = useAuthStore()
const router = useRouter()
const toasts = useToastStore()
const users = ref<PlatformUser[]>([])
const loading = ref(true)
const failed = ref(false)
const search = ref('')

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const { data } = await http.get('/platform/users', { params: { q: search.value || undefined } })
    users.value = data.data
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

async function impersonate(user: PlatformUser): Promise<void> {
  if (!confirm(`¿Impersonar a ${user.name}?`)) return
  try {
    await http.post(`/platform/impersonate/${user.id}`)
    await auth.fetchMe()
    toasts.success('Impersonando usuario.')
    router.push('/app')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between gap-4">
      <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Usuarios</h1>
      <input v-model="search" type="search" placeholder="Buscar…" class="input w-64" @keyup.enter="load" />
    </div>

    <div v-if="loading" class="card p-6"><div v-for="n in 6" :key="n" class="skeleton my-2 h-8 w-full" /></div>
    <ErrorState v-else-if="failed" @retry="load" />

    <div v-else class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
          <thead class="border-b border-slate-100 text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
            <tr>
              <th class="px-5 py-3 font-semibold">Usuario</th>
              <th class="px-5 py-3 font-semibold">Orgs</th>
              <th class="px-5 py-3 font-semibold">MFA</th>
              <th class="px-5 py-3 font-semibold">Verificado</th>
              <th class="px-5 py-3 font-semibold"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
            <tr v-for="user in users" :key="user.id" class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
              <td class="px-5 py-3">
                <p class="flex items-center gap-2 font-medium text-slate-900 dark:text-white">
                  {{ user.name }}
                  <span v-if="user.is_platform_admin" class="rounded-full bg-rose-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-rose-600">
                    Admin
                  </span>
                </p>
                <p class="text-xs text-slate-400">{{ user.email }}</p>
              </td>
              <td class="px-5 py-3 text-slate-500">{{ user.organizations_count ?? 0 }}</td>
              <td class="px-5 py-3">{{ user.two_factor_enabled ? '✓' : '—' }}</td>
              <td class="px-5 py-3">{{ user.email_verified ? '✓' : '—' }}</td>
              <td class="px-5 py-3 text-right">
                <button v-if="!user.is_platform_admin" class="btn-ghost text-xs" @click="impersonate(user)">
                  Impersonar
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
