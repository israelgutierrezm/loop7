<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue'
import http from '@/services/http'
import { date } from '@/utils/format'
import PageHeader from '@/components/ui/PageHeader.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import StatusBadge, { type BadgeTone } from '@/components/ui/StatusBadge.vue'

interface Row {
  id: string
  organization: { id: string; name: string }
  plan: string | null
  plan_name: string | null
  status: string
  status_label: string
  interval: string | null
  gateway: string | null
  trial_ends_at: string | null
  current_period_end: string | null
  cancel_at_period_end: boolean
}

const rows = ref<Row[]>([])
const loading = ref(true)
const failed = ref(false)
const page = ref(1)
const lastPage = ref(1)
const filters = reactive({ status: '', search: '' })

const statuses = [
  { value: '', label: 'Todos los estados' },
  { value: 'trialing', label: 'En prueba' },
  { value: 'active', label: 'Activas' },
  { value: 'grace', label: 'En periodo de gracia' },
  { value: 'suspended', label: 'Suspendidas' },
  { value: 'expired', label: 'Expiradas' },
  { value: 'cancelled', label: 'Canceladas' },
]

function tone(status: string): BadgeTone {
  return ({ active: 'success', trialing: 'info', grace: 'warning' } as Record<string, BadgeTone>)[status] ?? 'danger'
}

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const { data } = await http.get('/platform/subscriptions', {
      params: { status: filters.status || undefined, search: filters.search || undefined, page: page.value },
    })
    rows.value = data.data
    lastPage.value = data.meta.last_page ?? 1
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

watch(() => filters.status, () => {
  page.value = 1
  load()
})

onMounted(load)
</script>

<template>
  <div>
    <PageHeader title="Suscripciones" description="Estado comercial de cada organización. Gestiona plan, trial y excepciones desde su ficha." />

    <div class="mb-4 flex flex-wrap gap-3">
      <label for="sub-status" class="sr-only">Estado</label>
      <select id="sub-status" v-model="filters.status" class="input w-auto">
        <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
      </select>
      <label for="sub-search" class="sr-only">Buscar organización</label>
      <input id="sub-search" v-model="filters.search" type="search" placeholder="Buscar organización…" class="input w-64" @keyup.enter="page = 1; load()" />
    </div>

    <div v-if="loading" class="card p-6"><div v-for="n in 6" :key="n" class="skeleton my-2 h-8 w-full" /></div>
    <ErrorState v-else-if="failed" @retry="load" />
    <EmptyState v-else-if="rows.length === 0" icon="refresh" title="Sin suscripciones con ese filtro" />

    <div v-else class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
          <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50">
            <tr>
              <th scope="col" class="px-5 py-3 font-medium">Organización</th>
              <th scope="col" class="px-5 py-3 font-medium">Plan</th>
              <th scope="col" class="px-5 py-3 font-medium">Estado</th>
              <th scope="col" class="px-5 py-3 font-medium">Pago</th>
              <th scope="col" class="px-5 py-3 font-medium">Vence</th>
              <th scope="col" class="px-5 py-3"><span class="sr-only">Acciones</span></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
            <tr v-for="r in rows" :key="r.id" class="hover:bg-slate-50 dark:hover:bg-slate-800/40">
              <td class="px-5 py-3 font-medium text-slate-900 dark:text-white">{{ r.organization.name }}</td>
              <td class="px-5 py-3">
                {{ r.plan_name ?? '—' }}
                <span v-if="r.interval" class="text-xs text-slate-400">· {{ r.interval === 'year' ? 'anual' : 'mensual' }}</span>
              </td>
              <td class="px-5 py-3">
                <StatusBadge :tone="tone(r.status)" dot>{{ r.status_label }}</StatusBadge>
                <span v-if="r.cancel_at_period_end" class="ml-1 text-xs text-rose-600">· cancela al final</span>
              </td>
              <td class="px-5 py-3 text-slate-500">{{ r.gateway ?? '—' }}</td>
              <td class="px-5 py-3 text-slate-500">{{ date(r.status === 'trialing' ? r.trial_ends_at : r.current_period_end) }}</td>
              <td class="px-5 py-3 text-right">
                <RouterLink :to="`/platform/organizations/${r.organization.id}`" class="btn-ghost px-3 py-1 text-xs">Gestionar</RouterLink>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="lastPage > 1" class="flex items-center justify-end gap-2 border-t border-slate-100 px-5 py-3 text-sm dark:border-slate-800">
        <button type="button" class="btn-ghost px-3 py-1 text-xs" :disabled="page <= 1" @click="page--; load()">Anterior</button>
        <span class="text-slate-500">Página {{ page }} de {{ lastPage }}</span>
        <button type="button" class="btn-ghost px-3 py-1 text-xs" :disabled="page >= lastPage" @click="page++; load()">Siguiente</button>
      </div>
    </div>
  </div>
</template>
