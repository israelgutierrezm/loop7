<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import http from '@/services/http'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage } from '@/utils/errors'
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
interface JobsHealth {
  queued: number
  failed_count: number
  failed: FailedJob[]
}

const toasts = useToastStore()
const health = ref<JobsHealth | null>(null)
const loading = ref(true)
const failed = ref(false)
const busyId = ref<string | null>(null)

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
    await load(true)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busyId.value = null
  }
}

async function forget(job: FailedJob): Promise<void> {
  if (!confirm('¿Descartar este trabajo fallido? No se podrá reintentar.')) return
  busyId.value = job.id
  try {
    await http.delete(`/platform/jobs/${job.id}`)
    toasts.success('Trabajo descartado.')
    await load(true)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busyId.value = null
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
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Colas y trabajos</h1>
        <p class="text-sm text-slate-500">Salud de las colas de publicación, webhooks y sincronizaciones.</p>
      </div>
      <button class="btn-secondary text-sm" :disabled="loading" @click="load()">
        <AppIcon name="refresh" :size="16" /> Actualizar
      </button>
    </div>

    <div v-if="loading" class="card p-6"><div class="skeleton h-32 w-full" /></div>
    <ErrorState v-else-if="failed" @retry="load" />

    <template v-else-if="health">
      <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <StatCard label="En cola" :value="health.queued" icon="queue" hint="Trabajos pendientes de procesar" />
        <StatCard label="Fallidos" :value="health.failed_count" icon="alert" hint="Requieren reintento o descarte" />
      </div>

      <div class="card overflow-hidden">
        <div class="border-b border-slate-100 px-6 py-4 dark:border-slate-800">
          <h2 class="font-semibold text-slate-900 dark:text-white">Trabajos fallidos</h2>
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
              <button class="btn-secondary text-xs" :disabled="busyId === job.id" @click="retry(job)">
                <AppIcon name="refresh" :size="14" /> Reintentar
              </button>
              <button class="btn-secondary text-xs text-rose-600" :disabled="busyId === job.id" @click="forget(job)">
                <AppIcon name="close" :size="14" /> Descartar
              </button>
            </div>
          </li>
        </ul>
      </div>
    </template>
  </div>
</template>
