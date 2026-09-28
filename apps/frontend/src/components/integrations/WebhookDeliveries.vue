<script setup lang="ts">
import { ref, watch } from 'vue'
import http from '@/services/http'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage } from '@/utils/errors'
import ModalDialog from '@/components/ui/ModalDialog.vue'
import StatusBadge, { type BadgeTone } from '@/components/ui/StatusBadge.vue'
import AppIcon from '@/components/AppIcon.vue'

export interface WebhookDelivery {
  id: string
  event: string
  message_id: string
  status: 'pending' | 'succeeded' | 'failed'
  status_label: string
  attempts: number
  response_status: number | null
  response_body: string | null
  error: string | null
  duration_ms: number | null
  next_attempt_at: string | null
  delivered_at: string | null
  created_at: string | null
  payload: Record<string, unknown>
}

const props = defineProps<{ open: boolean; endpointId: string | null; endpointUrl: string; maxAttempts: number }>()
const emit = defineEmits<{ close: [] }>()

const toasts = useToastStore()
const items = ref<WebhookDelivery[]>([])
const loading = ref(false)
const failed = ref(false)
const status = ref('')
const page = ref(1)
const lastPage = ref(1)
const loadingMore = ref(false)
const expanded = ref<string | null>(null)
const resending = ref<string | null>(null)

const tones: Record<WebhookDelivery['status'], BadgeTone> = { pending: 'warning', succeeded: 'success', failed: 'danger' }

async function fetchPage(next: number): Promise<{ data: WebhookDelivery[]; last: number }> {
  const { data } = await http.get(`/webhooks/${props.endpointId}/deliveries`, {
    params: { page: next, status: status.value || undefined },
  })
  return { data: data.data as WebhookDelivery[], last: data.meta?.last_page ?? next }
}

async function load(): Promise<void> {
  if (!props.endpointId) return
  loading.value = true
  failed.value = false
  try {
    const result = await fetchPage(1)
    items.value = result.data
    page.value = 1
    lastPage.value = result.last
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

async function loadMore(): Promise<void> {
  if (loadingMore.value || page.value >= lastPage.value) return
  loadingMore.value = true
  try {
    const result = await fetchPage(page.value + 1)
    const known = new Set(items.value.map((d) => d.id))
    items.value.push(...result.data.filter((d) => !known.has(d.id)))
    page.value += 1
    lastPage.value = result.last
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    loadingMore.value = false
  }
}

async function redeliver(d: WebhookDelivery): Promise<void> {
  resending.value = d.id
  try {
    await http.post(`/webhooks/${props.endpointId}/deliveries/${d.id}/redeliver`)
    toasts.success('Mensaje reenviado.')
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    resending.value = null
  }
}

function fmt(value: string | null): string {
  return value ? new Date(value).toLocaleString('es', { dateStyle: 'short', timeStyle: 'medium' }) : '—'
}

watch(() => [props.open, props.endpointId], () => {
  if (props.open) {
    status.value = ''
    expanded.value = null
    load()
  }
})
watch(status, () => props.open && load())
</script>

<template>
  <ModalDialog :open="open" title="Entregas del webhook" :description="endpointUrl" size="xl" @close="emit('close')">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
      <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
        Estado
        <select v-model="status" class="input w-auto py-1 text-sm">
          <option value="">Todas</option>
          <option value="succeeded">Entregadas</option>
          <option value="pending">Pendientes</option>
          <option value="failed">Fallidas</option>
        </select>
      </label>
      <button type="button" class="btn-ghost text-xs" :disabled="loading" @click="load">
        <AppIcon name="refresh" :size="14" /> Actualizar
      </button>
    </div>

    <div v-if="loading" class="skeleton h-40 w-full" />
    <p v-else-if="failed" class="text-sm text-rose-600">No se pudieron cargar las entregas.</p>
    <p v-else-if="items.length === 0" class="py-8 text-center text-sm text-slate-500">
      Todavía no hay entregas. Usa «Probar» para enviar un mensaje de prueba.
    </p>

    <div v-else class="max-h-[60vh] space-y-2 overflow-y-auto pr-1">
      <div v-for="d in items" :key="d.id" class="rounded-lg border border-slate-100 dark:border-slate-800">
        <button
          type="button"
          class="flex w-full flex-wrap items-center gap-x-3 gap-y-1 p-3 text-left"
          :aria-expanded="expanded === d.id"
          @click="expanded = expanded === d.id ? null : d.id"
        >
          <StatusBadge :tone="tones[d.status]" dot>{{ d.status_label }}</StatusBadge>
          <span class="font-mono text-xs text-slate-700 dark:text-slate-200">{{ d.event }}</span>
          <span class="text-xs text-slate-400">{{ fmt(d.created_at) }}</span>
          <span class="ml-auto flex items-center gap-3 text-xs text-slate-500">
            <span v-if="d.response_status">HTTP {{ d.response_status }}</span>
            <span v-if="d.duration_ms !== null">{{ d.duration_ms }} ms</span>
            <span>{{ d.attempts }}/{{ maxAttempts }} intentos</span>
            <AppIcon :name="expanded === d.id ? 'chevron-down' : 'chevron-right'" :size="14" />
          </span>
        </button>

        <div v-if="expanded === d.id" class="space-y-3 border-t border-slate-100 p-3 text-xs dark:border-slate-800">
          <p v-if="d.error" class="rounded bg-rose-50 px-2 py-1.5 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300">{{ d.error }}</p>
          <p v-if="d.status === 'pending' && d.next_attempt_at" class="text-slate-500">
            Próximo intento: {{ fmt(d.next_attempt_at) }}
          </p>
          <p class="text-slate-500">
            <span class="font-medium">webhook-id:</span> <code class="font-mono">{{ d.message_id }}</code>
          </p>
          <div>
            <p class="mb-1 font-medium text-slate-600 dark:text-slate-300">Cuerpo enviado</p>
            <pre class="max-h-64 overflow-auto rounded bg-slate-50 p-2 font-mono text-[11px] leading-relaxed dark:bg-slate-900">{{ JSON.stringify(d.payload, null, 2) }}</pre>
          </div>
          <div v-if="d.response_body">
            <p class="mb-1 font-medium text-slate-600 dark:text-slate-300">Respuesta del servidor</p>
            <pre class="max-h-40 overflow-auto whitespace-pre-wrap break-all rounded bg-slate-50 p-2 font-mono text-[11px] dark:bg-slate-900">{{ d.response_body }}</pre>
          </div>
          <div v-if="d.status !== 'pending'" class="flex justify-end">
            <button type="button" class="btn-secondary text-xs" :disabled="resending === d.id" @click="redeliver(d)">
              <AppIcon name="refresh" :size="14" /> {{ resending === d.id ? 'Reenviando…' : 'Reenviar' }}
            </button>
          </div>
        </div>
      </div>

      <div v-if="page < lastPage" class="flex justify-center pt-1">
        <button type="button" class="btn-secondary text-sm" :disabled="loadingMore" @click="loadMore">
          {{ loadingMore ? 'Cargando…' : 'Cargar más' }}
        </button>
      </div>
    </div>
  </ModalDialog>
</template>
