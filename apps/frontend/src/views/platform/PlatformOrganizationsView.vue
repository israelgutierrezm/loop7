<script setup lang="ts">
import { onMounted, ref } from 'vue'
import http from '@/services/http'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage } from '@/utils/errors'
import ErrorState from '@/components/ui/ErrorState.vue'
import EmptyState from '@/components/ui/EmptyState.vue'

interface PlatformOrg {
  id: string
  name: string
  slug: string
  status: string
  billing_email: string | null
  members_count: number | null
  brands_count: number | null
  created_at: string | null
}

const toasts = useToastStore()
const orgs = ref<PlatformOrg[]>([])
const loading = ref(true)
const failed = ref(false)
const search = ref('')

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const { data } = await http.get('/platform/organizations', { params: { q: search.value || undefined } })
    orgs.value = data.data
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

async function toggle(org: PlatformOrg): Promise<void> {
  const action = org.status === 'suspended' ? 'activate' : 'suspend'
  try {
    const { data } = await http.post(`/platform/organizations/${org.id}/${action}`)
    org.status = data.data.status
    toasts.success(action === 'suspend' ? 'Organización suspendida.' : 'Organización reactivada.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between gap-4">
      <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Organizaciones</h1>
      <input v-model="search" type="search" placeholder="Buscar…" class="input w-64" @keyup.enter="load" />
    </div>

    <div v-if="loading" class="card p-6"><div v-for="n in 6" :key="n" class="skeleton my-2 h-8 w-full" /></div>
    <ErrorState v-else-if="failed" @retry="load" />
    <EmptyState v-else-if="orgs.length === 0" icon="building" title="Sin organizaciones" />

    <div v-else class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
          <thead class="border-b border-slate-100 text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
            <tr>
              <th class="px-5 py-3 font-semibold">Organización</th>
              <th class="px-5 py-3 font-semibold">Estado</th>
              <th class="px-5 py-3 font-semibold">Miembros</th>
              <th class="px-5 py-3 font-semibold">Marcas</th>
              <th class="px-5 py-3 font-semibold"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
            <tr v-for="org in orgs" :key="org.id" class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
              <td class="px-5 py-3">
                <p class="font-medium text-slate-900 dark:text-white">{{ org.name }}</p>
                <p class="text-xs text-slate-400">{{ org.billing_email ?? org.slug }}</p>
              </td>
              <td class="px-5 py-3">
                <span
                  class="rounded-full px-2 py-0.5 text-xs font-semibold"
                  :class="org.status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'"
                >
                  {{ org.status }}
                </span>
              </td>
              <td class="px-5 py-3 text-slate-500">{{ org.members_count ?? '—' }}</td>
              <td class="px-5 py-3 text-slate-500">{{ org.brands_count ?? '—' }}</td>
              <td class="px-5 py-3 text-right">
                <button class="btn-ghost text-xs" @click="toggle(org)">
                  {{ org.status === 'suspended' ? 'Reactivar' : 'Suspender' }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
