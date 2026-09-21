<script setup lang="ts">
import { onMounted, ref } from 'vue'
import http from '@/services/http'
import PageHeader from '@/components/ui/PageHeader.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'

interface AuditEntry {
  id: string
  action: string
  actor: { name: string; email: string } | null
  ip_address: string | null
  created_at: string | null
}

const logs = ref<AuditEntry[]>([])
const loading = ref(true)
const failed = ref(false)

function formatDate(iso: string | null): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleString('es', { dateStyle: 'medium', timeStyle: 'short' })
}

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const { data } = await http.get('/audit-logs')
    logs.value = data.data
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader title="Auditoría" description="Registro inmutable de acciones en tu organización." />

    <div v-if="loading" class="card p-6">
      <div v-for="n in 6" :key="n" class="skeleton my-2 h-8 w-full" />
    </div>

    <ErrorState v-else-if="failed" @retry="load" />

    <EmptyState v-else-if="logs.length === 0" icon="shield" title="Sin eventos" description="Aún no se registran acciones auditables." />

    <div v-else class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
          <thead class="border-b border-slate-100 text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
            <tr>
              <th class="px-5 py-3 font-semibold">Acción</th>
              <th class="px-5 py-3 font-semibold">Usuario</th>
              <th class="px-5 py-3 font-semibold">IP</th>
              <th class="px-5 py-3 font-semibold">Fecha</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
            <tr v-for="log in logs" :key="log.id" class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
              <td class="px-5 py-3">
                <code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                  {{ log.action }}
                </code>
              </td>
              <td class="px-5 py-3 text-slate-600 dark:text-slate-300">{{ log.actor?.name ?? 'Sistema' }}</td>
              <td class="px-5 py-3 text-slate-400">{{ log.ip_address ?? '—' }}</td>
              <td class="px-5 py-3 text-slate-400">{{ formatDate(log.created_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
