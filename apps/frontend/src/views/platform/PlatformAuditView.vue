<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue'
import http from '@/services/http'
import { dateTime } from '@/utils/format'
import PageHeader from '@/components/ui/PageHeader.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import EmptyState from '@/components/ui/EmptyState.vue'

interface Log {
  id: string
  action: string
  properties: Record<string, unknown> | null
  organization: { id: string; name: string } | null
  actor: { name: string; email: string } | null
  ip_address: string | null
  created_at: string | null
}

const logs = ref<Log[]>([])
const loading = ref(true)
const failed = ref(false)
const page = ref(1)
const lastPage = ref(1)
const filters = reactive({ action: '', search: '', platform_only: false })
const expanded = ref<string | null>(null)

const groups = [
  { value: '', label: 'Todas las acciones' },
  { value: 'auth.', label: 'Accesos' },
  { value: 'superadmin.', label: 'Superadmin' },
  { value: 'organization.', label: 'Organizaciones' },
  { value: 'member.', label: 'Equipo' },
  { value: 'subscription.', label: 'Suscripciones' },
  { value: 'billing.', label: 'Billing' },
  { value: 'payment.', label: 'Pagos' },
  { value: 'social.', label: 'Redes sociales' },
  { value: 'content.', label: 'Contenido' },
  { value: 'ai.', label: 'IA' },
  { value: 'platform.', label: 'Configuración de plataforma' },
]

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const { data } = await http.get('/platform/audit-logs', {
      params: {
        action: filters.action || undefined,
        search: filters.search || undefined,
        platform_only: filters.platform_only ? 1 : undefined,
        page: page.value,
      },
    })
    logs.value = data.data
    lastPage.value = data.meta.last_page ?? 1
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

watch(() => [filters.action, filters.platform_only], () => {
  page.value = 1
  load()
})
onMounted(load)
</script>

<template>
  <div>
    <PageHeader title="Auditoría global" description="Registro inmutable de las acciones críticas de todas las organizaciones y de la administración." />

    <div class="mb-4 flex flex-wrap items-center gap-3">
      <label for="au-action" class="sr-only">Tipo de acción</label>
      <select id="au-action" v-model="filters.action" class="input w-auto">
        <option v-for="g in groups" :key="g.value" :value="g.value">{{ g.label }}</option>
      </select>
      <label for="au-search" class="sr-only">Buscar por usuario</label>
      <input id="au-search" v-model="filters.search" type="search" class="input w-64" placeholder="Buscar por nombre o correo…" @keyup.enter="page = 1; load()" />
      <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
        <input v-model="filters.platform_only" type="checkbox" class="rounded border-slate-300 text-brand-600" /> Sólo acciones de plataforma
      </label>
    </div>

    <div v-if="loading" class="card p-6"><div v-for="n in 8" :key="n" class="skeleton my-2 h-8 w-full" /></div>
    <ErrorState v-else-if="failed" @retry="load" />
    <EmptyState v-else-if="logs.length === 0" icon="shield" title="Sin eventos con ese filtro" />

    <div v-else class="card overflow-hidden">
      <ul class="divide-y divide-slate-100 dark:divide-slate-800">
        <li v-for="log in logs" :key="log.id" class="px-5 py-3">
          <button
            type="button"
            class="flex w-full flex-wrap items-center justify-between gap-2 text-left"
            :aria-expanded="expanded === log.id ? 'true' : 'false'"
            @click="expanded = expanded === log.id ? null : log.id"
          >
            <span class="min-w-0">
              <span class="font-mono text-xs font-semibold text-brand-700 dark:text-brand-300">{{ log.action }}</span>
              <span class="ml-2 text-sm text-slate-700 dark:text-slate-200">{{ log.actor?.name ?? 'Sistema' }}</span>
              <span v-if="log.organization" class="ml-1 text-sm text-slate-400">· {{ log.organization.name }}</span>
            </span>
            <span class="text-xs text-slate-400">{{ dateTime(log.created_at) }}<template v-if="log.ip_address"> · {{ log.ip_address }}</template></span>
          </button>
          <pre
            v-if="expanded === log.id && log.properties && Object.keys(log.properties).length"
            class="mt-2 overflow-x-auto rounded-lg bg-slate-50 p-3 text-xs text-slate-600 dark:bg-slate-800/60 dark:text-slate-300"
          >{{ JSON.stringify(log.properties, null, 2) }}</pre>
        </li>
      </ul>
      <div v-if="lastPage > 1" class="flex items-center justify-end gap-2 border-t border-slate-100 px-5 py-3 text-sm dark:border-slate-800">
        <button type="button" class="btn-ghost px-3 py-1 text-xs" :disabled="page <= 1" @click="page--; load()">Anterior</button>
        <span class="text-slate-500">Página {{ page }} de {{ lastPage }}</span>
        <button type="button" class="btn-ghost px-3 py-1 text-xs" :disabled="page >= lastPage" @click="page++; load()">Siguiente</button>
      </div>
    </div>
  </div>
</template>
