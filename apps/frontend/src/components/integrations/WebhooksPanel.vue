<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import http from '@/services/http'
import { useToastStore } from '@/stores/toasts'
import { useConfirmStore } from '@/stores/confirm'
import { apiErrorMessage, apiValidationErrors } from '@/utils/errors'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import ModalDialog from '@/components/ui/ModalDialog.vue'
import CopyField from '@/components/ui/CopyField.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import AppIcon from '@/components/AppIcon.vue'
import WebhookDeliveries from '@/components/integrations/WebhookDeliveries.vue'

interface Endpoint {
  id: string
  url: string
  description: string | null
  events: string[]
  is_active: boolean
  secret_hint: string
  rotating_until: string | null
  consecutive_failures: number
  disabled_at: string | null
  disabled_reason: string | null
  last_delivery_at: string | null
  created_at: string | null
}
interface Limits {
  max_endpoints: number
  max_attempts: number
  disable_after_failures: number
  rotation_grace_hours: number
  signature_tolerance_seconds: number
}

const toasts = useToastStore()
const confirmDialog = useConfirmStore()

const endpoints = ref<Endpoint[]>([])
const events = ref<{ value: string; label: string }[]>([])
const limits = ref<Limits | null>(null)
const loading = ref(true)
const failed = ref(false)

const editorOpen = ref(false)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})
const form = reactive({ id: '', url: '', description: '', events: [] as string[] })

// Secreto recién creado o rotado: se muestra una sola vez.
const secret = ref<{ value: string; rotated: boolean } | null>(null)
const testing = ref<string | null>(null)
const deliveriesFor = ref<Endpoint | null>(null)
const helpOpen = ref(false)

const eventLabels = computed(() => Object.fromEntries(events.value.map((e) => [e.value, e.label])))
const atLimit = computed(() => limits.value !== null && endpoints.value.length >= limits.value.max_endpoints)

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const { data } = await http.get('/webhooks')
    endpoints.value = data.data.endpoints
    events.value = data.data.events
    limits.value = data.data.limits
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

function openCreate(): void {
  Object.assign(form, { id: '', url: '', description: '', events: [] })
  errors.value = {}
  editorOpen.value = true
}

function openEdit(e: Endpoint): void {
  Object.assign(form, { id: e.id, url: e.url, description: e.description ?? '', events: [...e.events] })
  errors.value = {}
  editorOpen.value = true
}

function fieldError(key: string): string | undefined {
  return errors.value[key]?.[0] ?? (key === 'events' ? errors.value['events.0']?.[0] : undefined)
}

async function save(): Promise<void> {
  saving.value = true
  errors.value = {}
  const payload = { url: form.url.trim(), description: form.description.trim() || null, events: form.events }
  try {
    if (form.id) {
      await http.patch(`/webhooks/${form.id}`, payload)
      toasts.success('Webhook actualizado.')
    } else {
      const { data } = await http.post('/webhooks', payload)
      secret.value = { value: data.data.secret, rotated: false }
    }
    editorOpen.value = false
    await load()
  } catch (e) {
    errors.value = apiValidationErrors(e)
    toasts.error(Object.keys(errors.value).length ? 'Revisa los campos marcados.' : apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

async function toggle(e: Endpoint): Promise<void> {
  try {
    const { data } = await http.patch(`/webhooks/${e.id}`, { is_active: !e.is_active })
    Object.assign(e, data.data)
    toasts.success(e.is_active ? 'Webhook activado.' : 'Webhook pausado.')
  } catch (err) {
    toasts.error(apiErrorMessage(err))
  }
}

async function test(e: Endpoint): Promise<void> {
  testing.value = e.id
  try {
    const { data } = await http.post(`/webhooks/${e.id}/test`)
    const d = data.data
    if (d.status === 'succeeded') {
      toasts.success(`Respondió ${d.response_status} en ${d.duration_ms} ms.`)
    } else {
      toasts.error(d.error ?? 'La prueba falló.')
    }
  } catch (err) {
    toasts.error(apiErrorMessage(err))
  } finally {
    testing.value = null
  }
}

async function rotate(e: Endpoint): Promise<void> {
  const hours = limits.value?.rotation_grace_hours ?? 24
  const ok = await confirmDialog.ask({
    title: 'Renovar el secreto de firma',
    message: `Se generará un secreto nuevo. Durante ${hours} h los mensajes irán firmados con ambos, para que actualices tu servidor sin perder ninguno.`,
    confirmText: 'Renovar',
  })
  if (!ok) return
  try {
    const { data } = await http.post(`/webhooks/${e.id}/rotate-secret`)
    secret.value = { value: data.data.secret, rotated: true }
    await load()
  } catch (err) {
    toasts.error(apiErrorMessage(err))
  }
}

async function remove(e: Endpoint): Promise<void> {
  const ok = await confirmDialog.ask({
    title: 'Eliminar webhook',
    message: `Dejarán de enviarse mensajes a ${e.url} y se borrará su historial de entregas.`,
    confirmText: 'Eliminar',
    danger: true,
  })
  if (!ok) return
  try {
    await http.delete(`/webhooks/${e.id}`)
    endpoints.value = endpoints.value.filter((x) => x.id !== e.id)
    toasts.success('Webhook eliminado.')
  } catch (err) {
    toasts.error(apiErrorMessage(err))
  }
}

function fmt(value: string | null): string {
  return value ? new Date(value).toLocaleString('es', { dateStyle: 'medium', timeStyle: 'short' }) : '—'
}

const nodeSnippet = `import { Webhook } from 'standardwebhooks'

// Cuerpo SIN parsear (raw) y cabeceras webhook-id, webhook-timestamp, webhook-signature.
const wh = new Webhook(process.env.LOOP7_WEBHOOK_SECRET) // whsec_…
const event = wh.verify(rawBody, headers) // lanza error si la firma no es válida`

const phpSnippet = `$id = $_SERVER['HTTP_WEBHOOK_ID'];
$ts = (int) $_SERVER['HTTP_WEBHOOK_TIMESTAMP'];
$body = file_get_contents('php://input');
$key = base64_decode(substr(getenv('LOOP7_WEBHOOK_SECRET'), 6)); // sin "whsec_"
$expected = base64_encode(hash_hmac('sha256', "{$id}.{$ts}.{$body}", $key, true));

$valid = abs(time() - $ts) <= 300 && array_filter(
    explode(' ', $_SERVER['HTTP_WEBHOOK_SIGNATURE']),
    fn ($s) => hash_equals("v1,{$expected}", $s),
);`

onMounted(load)
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
      <div class="max-w-2xl">
        <p class="text-sm text-slate-600 dark:text-slate-300">
          Recibe un <strong>POST firmado</strong> en tu servidor cuando ocurra algo en Loop7: contenido aprobado o publicado,
          mensajes nuevos del inbox, cuentas que caducan…
        </p>
        <button type="button" class="mt-1 text-xs font-medium text-brand-600 hover:underline" :aria-expanded="helpOpen" @click="helpOpen = !helpOpen">
          {{ helpOpen ? 'Ocultar' : 'Cómo verificar la firma' }}
        </button>
      </div>
      <button class="btn-primary text-sm" :disabled="atLimit" :title="atLimit ? 'Alcanzaste el máximo de webhooks' : undefined" @click="openCreate">
        <AppIcon name="plus" :size="16" /> Nuevo webhook
      </button>
    </div>

    <div v-if="helpOpen" class="card mb-4 space-y-3 p-4 text-sm text-slate-600 dark:text-slate-300">
      <p>
        Los mensajes siguen la especificación abierta
        <a href="https://www.standardwebhooks.com/" target="_blank" rel="noopener noreferrer" class="font-medium text-brand-600 hover:underline">Standard Webhooks</a>:
        cabeceras <code class="rounded bg-slate-100 px-1 dark:bg-slate-800">webhook-id</code>,
        <code class="rounded bg-slate-100 px-1 dark:bg-slate-800">webhook-timestamp</code> y
        <code class="rounded bg-slate-100 px-1 dark:bg-slate-800">webhook-signature</code>
        (HMAC-SHA256 de <code class="rounded bg-slate-100 px-1 dark:bg-slate-800">id.timestamp.cuerpo</code>).
        Rechaza mensajes con más de {{ Math.round((limits?.signature_tolerance_seconds ?? 300) / 60) }} minutos de antigüedad y
        usa <code class="rounded bg-slate-100 px-1 dark:bg-slate-800">webhook-id</code> para descartar duplicados.
      </p>
      <p>
        Responde con un código 2xx en menos de 10 s. Si falla, se reintenta hasta {{ limits?.max_attempts ?? 7 }} veces durante unas 21 horas;
        tras {{ limits?.disable_after_failures ?? 15 }} mensajes fallidos seguidos, el webhook se desactiva y te avisamos.
      </p>
      <div class="grid gap-3 lg:grid-cols-2">
        <div>
          <p class="mb-1 text-xs font-medium text-slate-500">Node.js (librería standardwebhooks)</p>
          <pre class="overflow-auto rounded bg-slate-50 p-2 font-mono text-[11px] leading-relaxed dark:bg-slate-900">{{ nodeSnippet }}</pre>
        </div>
        <div>
          <p class="mb-1 text-xs font-medium text-slate-500">PHP (sin librerías)</p>
          <pre class="overflow-auto rounded bg-slate-50 p-2 font-mono text-[11px] leading-relaxed dark:bg-slate-900">{{ phpSnippet }}</pre>
        </div>
      </div>
    </div>

    <div v-if="loading" class="card p-6"><div class="skeleton h-32 w-full" /></div>
    <ErrorState v-else-if="failed" @retry="load" />
    <EmptyState
      v-else-if="endpoints.length === 0"
      icon="automations"
      title="Sin webhooks"
      description="Conecta tu CRM, Slack o cualquier servidor para enterarte de lo que pasa al momento."
    >
      <template #action>
        <button class="btn-primary text-sm" @click="openCreate">Nuevo webhook</button>
      </template>
    </EmptyState>

    <div v-else class="space-y-3">
      <div v-for="e in endpoints" :key="e.id" class="card p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
              <StatusBadge v-if="e.is_active" tone="success" dot>Activo</StatusBadge>
              <StatusBadge v-else-if="e.disabled_reason" tone="danger" dot>Desactivado por fallos</StatusBadge>
              <StatusBadge v-else tone="neutral" dot>Pausado</StatusBadge>
              <p class="min-w-0 truncate font-mono text-sm text-slate-900 dark:text-white" :title="e.url">{{ e.url }}</p>
            </div>
            <p v-if="e.description" class="mt-1 text-sm text-slate-500">{{ e.description }}</p>
            <div class="mt-2 flex flex-wrap gap-1">
              <span
                v-for="ev in e.events"
                :key="ev"
                class="rounded-md bg-slate-100 px-1.5 py-0.5 font-mono text-[10px] font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300"
                :title="eventLabels[ev]"
              >{{ ev }}</span>
            </div>
            <p v-if="e.disabled_reason" class="mt-2 text-xs text-rose-600">{{ e.disabled_reason }} Revisa tu servidor y vuelve a activarlo.</p>
            <p v-else-if="e.consecutive_failures > 0" class="mt-2 text-xs text-amber-600">
              {{ e.consecutive_failures }} mensaje(s) fallido(s) seguidos.
            </p>
            <p class="mt-2 text-xs text-slate-400">
              Secreto: <span class="font-mono">{{ e.secret_hint }}</span>
              <span v-if="e.rotating_until"> · el anterior firma hasta {{ fmt(e.rotating_until) }}</span>
              · Último envío: {{ fmt(e.last_delivery_at) }}
            </p>
          </div>
          <label class="flex cursor-pointer items-center gap-2 text-xs text-slate-500">
            <input
              type="checkbox"
              class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
              :checked="e.is_active"
              @change="toggle(e)"
            />
            {{ e.is_active ? 'Activo' : 'Pausado' }}
          </label>
        </div>
        <div class="mt-3 flex flex-wrap gap-2 border-t border-slate-100 pt-3 dark:border-slate-800">
          <button type="button" class="btn-secondary text-xs" :disabled="testing === e.id" @click="test(e)">
            {{ testing === e.id ? 'Enviando…' : 'Probar' }}
          </button>
          <button type="button" class="btn-secondary text-xs" @click="deliveriesFor = e">Entregas</button>
          <button type="button" class="btn-secondary text-xs" @click="openEdit(e)">Editar</button>
          <button type="button" class="btn-secondary text-xs" @click="rotate(e)"><AppIcon name="key" :size="14" /> Renovar secreto</button>
          <button type="button" class="btn-ghost ml-auto text-xs text-rose-600" @click="remove(e)">Eliminar</button>
        </div>
      </div>
      <p v-if="limits" class="text-xs text-slate-400">{{ endpoints.length }} de {{ limits.max_endpoints }} webhooks.</p>
    </div>

    <!-- Alta / edición -->
    <ModalDialog :open="editorOpen" :title="form.id ? 'Editar webhook' : 'Nuevo webhook'" @close="editorOpen = false">
      <form class="space-y-4" @submit.prevent="save">
        <div>
          <label for="wh-url" class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">URL de destino</label>
          <input id="wh-url" v-model="form.url" type="url" class="input font-mono text-sm" placeholder="https://tu-servidor.com/webhooks/loop7" required />
          <p v-if="fieldError('url')" class="mt-1 text-xs text-rose-600">{{ fieldError('url') }}</p>
          <p v-else class="mt-1 text-xs text-slate-500">Debe ser https y accesible desde Internet.</p>
        </div>
        <div>
          <label for="wh-desc" class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">Descripción (opcional)</label>
          <input id="wh-desc" v-model="form.description" class="input" maxlength="255" placeholder="Ej. Sincronización con el CRM" />
        </div>
        <fieldset>
          <legend class="mb-1 text-sm font-medium text-slate-700 dark:text-slate-300">Eventos</legend>
          <div class="space-y-1.5">
            <label v-for="ev in events" :key="ev.value" class="flex cursor-pointer items-start gap-2 text-sm">
              <input
                v-model="form.events"
                type="checkbox"
                class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                :value="ev.value"
              />
              <span>
                <span class="text-slate-700 dark:text-slate-200">{{ ev.label }}</span>
                <span class="ml-1 font-mono text-[11px] text-slate-400">{{ ev.value }}</span>
              </span>
            </label>
          </div>
          <p v-if="fieldError('events')" class="mt-1 text-xs text-rose-600">{{ fieldError('events') }}</p>
        </fieldset>
        <div class="flex justify-end gap-2">
          <button type="button" class="btn-secondary text-sm" @click="editorOpen = false">Cancelar</button>
          <button type="submit" class="btn-primary text-sm" :disabled="saving">{{ saving ? 'Guardando…' : 'Guardar' }}</button>
        </div>
      </form>
    </ModalDialog>

    <!-- Secreto (una sola vez) -->
    <ModalDialog :open="secret !== null" :title="secret?.rotated ? 'Secreto renovado' : 'Webhook creado'" @close="secret = null">
      <div v-if="secret" class="space-y-4">
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/30">
          <p class="mb-3 text-sm font-medium text-amber-800 dark:text-amber-200">
            Guarda este secreto de firma en tu servidor ahora: no volverá a mostrarse.
          </p>
          <CopyField label="Secreto de firma" :value="secret.value" />
        </div>
        <p v-if="secret.rotated" class="text-xs text-slate-500">
          Durante {{ limits?.rotation_grace_hours ?? 24 }} h los mensajes llevarán dos firmas (la nueva y la anterior).
        </p>
        <div class="flex justify-end">
          <button class="btn-primary text-sm" @click="secret = null">Entendido</button>
        </div>
      </div>
    </ModalDialog>

    <WebhookDeliveries
      :open="deliveriesFor !== null"
      :endpoint-id="deliveriesFor?.id ?? null"
      :endpoint-url="deliveriesFor?.url ?? ''"
      :max-attempts="limits?.max_attempts ?? 7"
      @close="deliveriesFor = null"
    />
  </div>
</template>
