<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import http from '@/services/http'
import { useToastStore } from '@/stores/toasts'
import { useConfirmStore } from '@/stores/confirm'
import { apiErrorMessage } from '@/utils/errors'
import PageHeader from '@/components/ui/PageHeader.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import StatCard from '@/components/StatCard.vue'
import AppIcon from '@/components/AppIcon.vue'

interface FailedJob {
  id: string
  queue: string
  connection: string
  name: string
  exception: string
  failed_at: string
}
interface QueueSize {
  name: string
  size: number | null
}
interface JobsHealth {
  connection: string
  queues: QueueSize[]
  queued: number
  failed_count: number
  failed: FailedJob[]
  list_limit: number
}

const toasts = useToastStore()
const confirmDialog = useConfirmStore()
const health = ref<JobsHealth | null>(null)
const loading = ref(true)
const failed = ref(false)
const busyId = ref<string | null>(null)
const bulkBusy = ref(false)

const queueLabels: Record<string, string> = {
  publishing: 'Publicación',
  default: 'General (pagos, avisos)',
  inbox: 'Inbox',
  analytics: 'Analítica',
  automations: 'Automatizaciones',
}

async function load(silent = false): Promise<void> {
  if (!silent) {
    loading.value = true
    failed.value = false
  }
  try {
    const { data } = await http.get('/platform/jobs')
    health.value = data.data
  } catch {
    if (!silent) failed.value = true
  } finally {
    loading.value = false
  }
}

async function retry(job: FailedJob): Promise<void> {
  busyId.value = job.id
  try {
    await http.post(`/platform/jobs/${job.id}/retry`)
    toasts.success('Trabajo reencolado.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busyId.value = null
    await load(true)
  }
}

async function forget(job: FailedJob): Promise<void> {
  const ok = await confirmDialog.ask({
    title: 'Descartar trabajo',
    message: 'El trabajo fallido se eliminará y ya no podrá reintentarse.',
    confirmText: 'Descartar',
    danger: true,
  })
  if (!ok) return
  busyId.value = job.id
  try {
    await http.delete(`/platform/jobs/${job.id}`)
    toasts.success('Trabajo descartado.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busyId.value = null
    await load(true)
  }
}

async function retryAll(): Promise<void> {
  const count = health.value?.failed_count ?? 0
  const ok = await confirmDialog.ask({
    title: 'Reintentar todos los fallidos',
    message: `Se reencolarán ${count} trabajos. Hazlo cuando la causa del fallo (credenciales, red, proveedor) ya esté resuelta.`,
    confirmText: 'Reintentar todos',
  })
  if (!ok) return
  bulkBusy.value = true
  try {
    const { data } = await http.post('/platform/jobs/retry-all')
    toasts.success(data.message)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    bulkBusy.value = false
    await load(true)
  }
}

async function flush(): Promise<void> {
  const count = health.value?.failed_count ?? 0
  const ok = await confirmDialog.ask({
    title: 'Vaciar trabajos fallidos',
    message: `Se eliminarán ${count} trabajos fallidos y ya no podrán reintentarse. Queda auditado.`,
    confirmText: 'Vaciar',
    danger: true,
  })
  if (!ok) return
  bulkBusy.value = true
  try {
    const { data } = await http.delete('/platform/jobs')
    toasts.success(data.message)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    bulkBusy.value = false
    await load(true)
  }
}

function formatDate(value: string): string {
  return new Date(value.includes('T') ? value : value.replace(' ', 'T') + 'Z').toLocaleString('es', {
    dateStyle: 'medium',
    timeStyle: 'short',
  })
}

// Refresco periódico ligero para vigilar la salud de las colas.
let poll: ReturnType<typeof setInterval> | null = null
onMounted(() => {
  void load()
  poll = setInterval(() => void load(true), 10000)
})
onUnmounted(() => {
  if (poll) clearInterval(poll)
})
</script>

<template>
  <div>
    <PageHeader title="Colas y trabajos" description="Salud de las colas de publicación, pagos, inbox, analítica y automatizaciones. Se actualiza cada 10 segundos.">
      <template #actions>
        <button class="btn-secondary text-sm" :disabled="loading" @click="load()">
          <AppIcon name="refresh" :size="16" /> Actualizar
        </button>
      </template>
    </PageHeader>

    <div v-if="loading" class="card p-6"><div class="skeleton h-32 w-full" /></div>
    <ErrorState v-else-if="failed" @retry="load" />

    <template v-else-if="health">
      <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <StatCard label="En cola" :value="health.queued" icon="queue" :hint="`Pendientes de procesar (conexión ${health.connection})`" />
        <StatCard label="Fallidos" :value="health.failed_count" icon="alert" hint="Requieren reintento o descarte" />
      </div>

      <div class="card mb-6 overflow-hidden">
        <h2 class="border-b border-slate-100 px-6 py-4 font-semibold text-slate-900 dark:border-slate-800 dark:text-white">Pendientes por cola</h2>
        <ul class="grid grid-cols-1 divide-y divide-slate-100 sm:grid-cols-5 sm:divide-x sm:divide-y-0 dark:divide-slate-800">
          <li v-for="q in health.queues" :key="q.name" class="px-5 py-4">
            <p class="text-xs text-slate-500">{{ queueLabels[q.name] ?? q.name }}</p>
            <p class="mt-1 text-xl font-semibold text-slate-900 dark:text-white">{{ q.size ?? '—' }}</p>
            <p class="font-mono text-[10px] text-slate-400">{{ q.name }}</p>
          </li>
        </ul>
      </div>

      <div class="card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-4 dark:border-slate-800">
          <div>
            <h2 class="font-semibold text-slate-900 dark:text-white">Trabajos fallidos</h2>
            <p v-if="health.failed_count > health.failed.length" class="text-xs text-slate-400">
              Se muestran los {{ health.failed.length }} más recientes de {{ health.failed_count }}.
            </p>
          </div>
          <div v-if="health.failed_count > 0" class="flex gap-2">
            <button class="btn-secondary text-xs" :disabled="bulkBusy" @click="retryAll">
              <AppIcon name="refresh" :size="14" /> Reintentar todos
            </button>
            <button class="btn-secondary text-xs text-rose-600" :disabled="bulkBusy" @click="flush">
              <AppIcon name="close" :size="14" /> Vaciar
            </button>
          </div>
        </div>

        <EmptyState
          v-if="health.failed.length === 0"
          icon="check"
          title="Sin trabajos fallidos"
          description="Todas las colas se están procesando correctamente."
          class="py-12"
        />

        <ul v-else class="divide-y divide-slate-100 dark:divide-slate-800">
          <li v-for="job in health.failed" :key="job.id" class="flex flex-col gap-3 p-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <span class="font-medium text-slate-900 dark:text-white">{{ job.name }}</span>
                <span class="rounded-md bg-slate-100 px-2 py-0.5 text-xs text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                  {{ job.connection }} · {{ job.queue }}
                </span>
              </div>
              <p class="mt-1 break-words font-mono text-xs text-rose-600 dark:text-rose-400">{{ job.exception }}</p>
              <p class="mt-1 text-xs text-slate-400">{{ formatDate(job.failed_at) }}</p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
              <button class="btn-secondary text-xs" :disabled="busyId === job.id || bulkBusy" @click="retry(job)">
                <AppIcon name="refresh" :size="14" /> Reintentar
              </button>
              <button class="btn-secondary text-xs text-rose-600" :disabled="busyId === job.id || bulkBusy" @click="forget(job)">
                <AppIcon name="close" :size="14" /> Descartar
              </button>
            </div>
          </li>
        </ul>
      </div>
    </template>
  </div>
</template>
