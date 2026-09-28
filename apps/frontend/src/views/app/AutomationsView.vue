<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import { useConfirmStore } from '@/stores/confirm'
import { apiErrorMessage, apiValidationErrors } from '@/utils/errors'
import PageHeader from '@/components/ui/PageHeader.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import ModalDialog from '@/components/ui/ModalDialog.vue'
import CopyField from '@/components/ui/CopyField.vue'
import StatusBadge, { type BadgeTone } from '@/components/ui/StatusBadge.vue'
import AppIcon from '@/components/AppIcon.vue'

interface Condition { field: string; operator: string; value: string }
interface ActionRow { type: string; config: Record<string, string> }
interface FeedState { title: string | null; last_polled_at: string | null; last_error: string | null; ready: boolean }
interface Automation {
  id: string
  name: string
  is_enabled: boolean
  trigger: string
  trigger_label: string
  trigger_config: { feed_url?: string }
  inbound_url: string | null
  feed: FeedState | null
  brand: string | null
  brand_name: string | null
  conditions: Condition[]
  actions: ActionRow[]
  run_count: number
  last_run_at: string | null
}
interface Run { id: string; status: 'success' | 'failed' | 'skipped'; message: string | null; created_at: string | null }
interface Meta {
  triggers: { value: string; label: string; description: string; fields: string[]; external: boolean }[]
  actions: { value: string; label: string; triggers: string[] | null }[]
  operators: { value: string; label: string }[]
  audiences: { value: string; label: string }[]
  feed_poll_minutes: number
}
interface FeedPreview {
  title: string
  items: { id: string; title: string; link: string | null; summary: string; published_at: string | null }[]
}
interface FieldDef {
  key: string
  label: string
  placeholder?: string
  kind: 'text' | 'textarea' | 'audience'
}

const auth = useAuthStore()
const toasts = useToastStore()
const confirmDialog = useConfirmStore()

const meta = ref<Meta | null>(null)
const PER_PAGE = 50
const items = ref<Automation[]>([])
const page = ref(1)
const lastPage = ref(1)
const loadingMore = ref(false)
const loading = ref(true)
const failed = ref(false)
const saving = ref(false)
const modalOpen = ref(false)
const errors = ref<Record<string, string[]>>({})

// Datos de la regla abierta en el editor que no se editan en el formulario.
const current = ref<Automation | null>(null)
const runs = ref<Run[]>([])
const runsLoading = ref(false)
const feedPreview = ref<FeedPreview | null>(null)
const previewing = ref(false)
const rotating = ref(false)

const newAction = (type = 'notify'): ActionRow => ({ type, config: type === 'notify' ? { audience: 'managers' } : {} })
const blank = () => ({
  id: '' as string,
  name: '',
  trigger: 'content.published',
  feed_url: '',
  brand: '',
  is_enabled: true,
  conditions: [] as Condition[],
  actions: [newAction()] as ActionRow[],
})
const form = reactive(blank())

// Configuración que pide cada tipo de acción.
const actionFields: Record<string, FieldDef[]> = {
  notify: [
    { key: 'message', label: 'Mensaje del aviso', placeholder: 'Ej. Se publicó {content_title} en {brand}', kind: 'text' },
    { key: 'audience', label: 'Avisar a', kind: 'audience' },
  ],
  webhook: [{ key: 'url', label: 'URL del webhook', placeholder: 'https://tu-servidor.com/hook', kind: 'text' }],
  create_draft: [
    { key: 'title', label: 'Título del borrador', placeholder: 'Ej. {title}', kind: 'text' },
    { key: 'body', label: 'Texto del borrador (opcional)', placeholder: 'Ej. {summary}\n\nLee más: {link}', kind: 'textarea' },
  ],
  inbox_reply: [{ key: 'message', label: 'Respuesta automática', placeholder: 'Ej. Hola {participant}, gracias por escribir.', kind: 'textarea' }],
  inbox_tag: [{ key: 'tag', label: 'Etiqueta', placeholder: 'Ej. urgente', kind: 'text' }],
}

const runTones: Record<Run['status'], BadgeTone> = { success: 'success', failed: 'danger', skipped: 'neutral' }
const runLabels: Record<Run['status'], string> = { success: 'Correcta', failed: 'Fallida', skipped: 'Omitida' }

const triggerMeta = computed(() => meta.value?.triggers.find((t) => t.value === form.trigger))
const triggerFields = computed(() => triggerMeta.value?.fields ?? [])
// El webhook entrante no tiene campos fijos: son los del JSON recibido.
const freeFields = computed(() => form.trigger === 'webhook.received')
const availableActions = computed(() => (meta.value?.actions ?? []).filter((a) => a.triggers === null || a.triggers.includes(form.trigger)))

function fieldError(path: string): string | undefined {
  return errors.value[path]?.[0]
}

function changeActionType(action: ActionRow, type: string): void {
  Object.assign(action, newAction(type))
}

function changeTrigger(): void {
  feedPreview.value = null
  // Quita las acciones que no aplican al nuevo disparador.
  const allowed = new Set(availableActions.value.map((a) => a.value))
  form.actions = form.actions.filter((a) => allowed.has(a.type))
  if (form.actions.length === 0) form.actions.push(newAction())
  if (!freeFields.value) {
    form.conditions = form.conditions.filter((c) => triggerFields.value.includes(c.field))
  }
}

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const [metaRes, listRes] = await Promise.all([
      http.get('/automations/meta'),
      http.get('/automations', { params: { per_page: PER_PAGE } }),
    ])
    meta.value = metaRes.data.data
    items.value = listRes.data.data
    page.value = 1
    lastPage.value = listRes.data.meta?.last_page ?? 1
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
    const { data } = await http.get('/automations', { params: { per_page: PER_PAGE, page: page.value + 1 } })
    const known = new Set(items.value.map((a) => a.id))
    items.value.push(...(data.data as Automation[]).filter((a) => !known.has(a.id)))
    page.value += 1
    lastPage.value = data.meta?.last_page ?? page.value
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    loadingMore.value = false
  }
}

function openCreate(): void {
  Object.assign(form, blank())
  current.value = null
  runs.value = []
  feedPreview.value = null
  errors.value = {}
  modalOpen.value = true
}

function fillForm(a: Automation): void {
  Object.assign(form, {
    id: a.id,
    name: a.name,
    trigger: a.trigger,
    feed_url: a.trigger_config?.feed_url ?? '',
    brand: a.brand ?? '',
    is_enabled: a.is_enabled,
    conditions: a.conditions.map((c) => ({ ...c })),
    actions: a.actions.map((ac) => ({ type: ac.type, config: { ...ac.config } })),
  })
  current.value = a
}

async function openEdit(a: Automation): Promise<void> {
  fillForm(a)
  feedPreview.value = null
  errors.value = {}
  modalOpen.value = true
  await loadDetail(a.id)
}

// Estado al día (feed, URL) y últimas ejecuciones.
async function loadDetail(id: string): Promise<void> {
  runsLoading.value = true
  try {
    const { data } = await http.get(`/automations/${id}`)
    current.value = data.data
    runs.value = data.data.runs ?? []
  } catch {
    runs.value = []
  } finally {
    runsLoading.value = false
  }
}

function addCondition(): void {
  form.conditions.push({ field: freeFields.value ? '' : triggerFields.value[0] ?? '', operator: 'equals', value: '' })
}
function addAction(): void {
  form.actions.push(newAction(availableActions.value[0]?.value ?? 'notify'))
}

function payloadOf(a: { name: string; trigger: string; feed_url: string; brand: string | null; is_enabled: boolean; conditions: Condition[]; actions: ActionRow[] }) {
  return {
    name: a.name,
    trigger: a.trigger,
    trigger_config: a.trigger === 'rss.item_published' ? { feed_url: a.feed_url.trim() } : null,
    brand: a.brand || null,
    is_enabled: a.is_enabled,
    conditions: a.conditions,
    actions: a.actions.map((ac) => ({ type: ac.type, config: ac.config })),
  }
}

async function save(): Promise<void> {
  if (!form.name.trim() || form.actions.length === 0) {
    toasts.error('Añade un nombre y al menos una acción.')
    return
  }
  saving.value = true
  errors.value = {}
  try {
    if (form.id) {
      await http.put(`/automations/${form.id}`, payloadOf(form))
      toasts.success('Automatización guardada.')
      modalOpen.value = false
    } else {
      const { data } = await http.post('/automations', payloadOf(form))
      const created = data.data as Automation
      if (created.inbound_url) {
        // Se queda abierta para copiar la URL recién generada.
        fillForm(created)
        runs.value = []
        toasts.success('Automatización creada: copia su URL.')
      } else {
        toasts.success('Automatización creada.')
        modalOpen.value = false
      }
    }
    await load()
  } catch (e) {
    errors.value = apiValidationErrors(e)
    toasts.error(Object.keys(errors.value).length ? 'Revisa los campos marcados.' : apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

async function previewFeed(): Promise<void> {
  if (!form.feed_url.trim()) return
  previewing.value = true
  feedPreview.value = null
  errors.value = {}
  try {
    const { data } = await http.post('/automations/feed-preview', { url: form.feed_url.trim() })
    feedPreview.value = data.data
  } catch (e) {
    const fieldErrors = apiValidationErrors(e)
    if (fieldErrors.url) {
      errors.value = { 'trigger_config.feed_url': fieldErrors.url }
    } else {
      toasts.error(apiErrorMessage(e))
    }
  } finally {
    previewing.value = false
  }
}

async function rotateUrl(): Promise<void> {
  if (!form.id) return
  const ok = await confirmDialog.ask({
    title: 'Renovar la URL',
    message: 'La URL actual dejará de funcionar al momento: tendrás que poner la nueva en la herramienta que envía los datos.',
    confirmText: 'Renovar',
  })
  if (!ok) return
  rotating.value = true
  try {
    const { data } = await http.post(`/automations/${form.id}/rotate-inbound-url`)
    current.value = data.data
    toasts.success('URL renovada.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    rotating.value = false
  }
}

async function toggle(a: Automation): Promise<void> {
  try {
    await http.put(`/automations/${a.id}`, payloadOf({ ...a, feed_url: a.trigger_config?.feed_url ?? '', is_enabled: !a.is_enabled }))
    a.is_enabled = !a.is_enabled
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function remove(a: Automation): Promise<void> {
  const ok = await confirmDialog.ask({ title: 'Eliminar automatización', message: `Se eliminará «${a.name}» y su historial de ejecuciones.`, confirmText: 'Eliminar', danger: true })
  if (!ok) return
  try {
    await http.delete(`/automations/${a.id}`)
    items.value = items.value.filter((x) => x.id !== a.id)
    toasts.success('Eliminada.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

function fmt(value: string | null): string {
  return value ? new Date(value).toLocaleString('es', { dateStyle: 'short', timeStyle: 'short' }) : '—'
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader title="Automatizaciones" description="Cuando ocurra algo, ejecuta acciones automáticamente.">
      <template #actions>
        <button class="btn-primary text-sm" @click="openCreate">
          <AppIcon name="plus" :size="16" /> Nueva automatización
        </button>
      </template>
    </PageHeader>

    <div v-if="loading" class="card p-6"><div class="skeleton h-40 w-full" /></div>
    <ErrorState v-else-if="failed" @retry="load" />
    <EmptyState
      v-else-if="items.length === 0"
      icon="automations"
      title="Sin automatizaciones"
      description="Crea tu primera regla para ahorrar trabajo repetitivo: avisos, borradores desde tu blog, respuestas del inbox…"
    >
      <template #action>
        <button class="btn-primary text-sm" @click="openCreate">Nueva automatización</button>
      </template>
    </EmptyState>

    <div v-else class="space-y-3">
      <div v-for="a in items" :key="a.id" class="card flex flex-wrap items-center justify-between gap-3 p-4">
        <div class="min-w-0">
          <div class="flex items-center gap-2">
            <span class="grid h-9 w-9 place-items-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-950/50">
              <AppIcon name="automations" :size="18" />
            </span>
            <div class="min-w-0">
              <p class="truncate font-semibold text-slate-900 dark:text-white">{{ a.name }}</p>
              <p class="truncate text-xs text-slate-400">
                {{ a.trigger_label }} · {{ a.actions.length }} acción(es)
                <span v-if="a.brand_name">· {{ a.brand_name }}</span>
                · {{ a.run_count }} ejecuciones
              </p>
              <p v-if="a.feed?.last_error" class="truncate text-xs text-rose-600">Feed: {{ a.feed.last_error }}</p>
              <p v-else-if="a.feed?.title" class="truncate text-xs text-slate-400">Feed: {{ a.feed.title }}</p>
            </div>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <label class="flex cursor-pointer items-center gap-2 text-xs text-slate-500">
            <input
              type="checkbox"
              class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
              :checked="a.is_enabled"
              @change="toggle(a)"
            />
            {{ a.is_enabled ? 'Activa' : 'Pausada' }}
          </label>
          <button class="btn-secondary text-xs" @click="openEdit(a)">Editar</button>
          <button class="btn-ghost text-xs text-rose-600" :aria-label="`Eliminar ${a.name}`" :title="`Eliminar ${a.name}`" @click="remove(a)">
            <AppIcon name="close" :size="14" />
          </button>
        </div>
      </div>
      <div v-if="page < lastPage" class="flex justify-center pt-2">
        <button type="button" class="btn-secondary text-sm" :disabled="loadingMore" @click="loadMore">
          {{ loadingMore ? 'Cargando…' : 'Cargar más automatizaciones' }}
        </button>
      </div>
    </div>

    <!-- Editor -->
    <ModalDialog :open="modalOpen" :title="form.id ? 'Editar automatización' : 'Nueva automatización'" size="lg" @close="modalOpen = false">
      <div class="max-h-[70vh] space-y-4 overflow-y-auto pr-1">
        <div>
          <label for="auto-name" class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">Nombre</label>
          <input id="auto-name" v-model="form.name" class="input" placeholder="Ej. Avisar al equipo al publicar" />
          <p v-if="fieldError('name')" class="mt-1 text-xs text-rose-600">{{ fieldError('name') }}</p>
        </div>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div>
            <label for="auto-trigger" class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">Cuando… (disparador)</label>
            <select id="auto-trigger" v-model="form.trigger" class="input" @change="changeTrigger">
              <option v-for="t in meta?.triggers" :key="t.value" :value="t.value">{{ t.label }}</option>
            </select>
          </div>
          <div>
            <label for="auto-brand" class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">Marca</label>
            <select id="auto-brand" v-model="form.brand" class="input">
              <option value="">Todas las marcas</option>
              <option v-for="b in auth.brands" :key="b.id" :value="b.id">{{ b.name }}</option>
            </select>
          </div>
        </div>
        <p v-if="triggerMeta" class="-mt-2 text-xs text-slate-500">{{ triggerMeta.description }}</p>

        <!-- Feed RSS -->
        <div v-if="form.trigger === 'rss.item_published'" class="space-y-2 rounded-lg border border-slate-100 p-3 dark:border-slate-800">
          <label for="auto-feed" class="block text-sm font-medium text-slate-700 dark:text-slate-300">URL del feed RSS o Atom</label>
          <div class="flex gap-2">
            <input id="auto-feed" v-model="form.feed_url" type="url" class="input font-mono text-xs" placeholder="https://tu-blog.com/feed" />
            <button type="button" class="btn-secondary shrink-0 text-xs" :disabled="previewing || !form.feed_url.trim()" @click="previewFeed">
              {{ previewing ? 'Leyendo…' : 'Probar feed' }}
            </button>
          </div>
          <p v-if="fieldError('trigger_config.feed_url')" class="text-xs text-rose-600">{{ fieldError('trigger_config.feed_url') }}</p>
          <div v-if="feedPreview" class="rounded bg-slate-50 p-2 text-xs dark:bg-slate-900">
            <p class="mb-1 font-medium text-slate-700 dark:text-slate-200">{{ feedPreview.title || 'Feed sin título' }} · últimas entradas:</p>
            <ul class="space-y-0.5 text-slate-600 dark:text-slate-300">
              <li v-for="item in feedPreview.items" :key="item.id" class="truncate">• {{ item.title || item.link }}</li>
              <li v-if="feedPreview.items.length === 0" class="text-slate-400">El feed no tiene entradas.</li>
            </ul>
          </div>
          <p v-if="current?.feed" class="text-xs" :class="current.feed.last_error ? 'text-rose-600' : 'text-slate-400'">
            <template v-if="current.feed.last_error">Último error: {{ current.feed.last_error }}</template>
            <template v-else-if="current.feed.last_polled_at">Revisado {{ fmt(current.feed.last_polled_at) }}{{ current.feed.title ? ` · ${current.feed.title}` : '' }}</template>
            <template v-else>Se revisará en los próximos minutos (cada {{ meta?.feed_poll_minutes ?? 15 }} min).</template>
          </p>
        </div>

        <!-- Webhook entrante -->
        <div v-if="form.trigger === 'webhook.received'" class="space-y-2 rounded-lg border border-slate-100 p-3 dark:border-slate-800">
          <template v-if="current?.inbound_url && current.trigger === 'webhook.received'">
            <CopyField label="URL secreta (POST con JSON o formulario)" :value="current.inbound_url" />
            <div class="flex flex-wrap items-center justify-between gap-2">
              <p class="text-xs text-slate-500">
                Trátala como una contraseña. Envía <code class="rounded bg-slate-100 px-1 dark:bg-slate-800">Idempotency-Key</code> para no procesar dos veces el mismo envío.
              </p>
              <button type="button" class="btn-ghost text-xs" :disabled="rotating" @click="rotateUrl">
                <AppIcon name="refresh" :size="14" /> Renovar URL
              </button>
            </div>
          </template>
          <p v-else class="text-xs text-slate-500">La URL secreta se generará al guardar.</p>
        </div>

        <!-- Condiciones -->
        <fieldset>
          <div class="mb-1 flex items-center justify-between">
            <legend class="text-sm font-medium text-slate-700 dark:text-slate-300">Si se cumple (opcional)</legend>
            <button type="button" class="btn-ghost text-xs" @click="addCondition"><AppIcon name="plus" :size="14" /> Condición</button>
          </div>
          <div v-for="(c, i) in form.conditions" :key="i" class="mb-2 flex flex-wrap items-center gap-2">
            <input
              v-if="freeFields"
              v-model="c.field"
              class="input w-auto flex-1 font-mono text-xs"
              placeholder="campo (p. ej. cliente.email)"
              :aria-label="`Campo de la condición ${i + 1}`"
            />
            <select v-else v-model="c.field" class="input w-auto flex-1" :aria-label="`Campo de la condición ${i + 1}`">
              <option v-for="f in triggerFields" :key="f" :value="f">{{ f }}</option>
            </select>
            <select v-model="c.operator" class="input w-auto" :aria-label="`Operador de la condición ${i + 1}`">
              <option v-for="o in meta?.operators" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
            <input v-model="c.value" class="input w-auto flex-1" placeholder="valor" :aria-label="`Valor de la condición ${i + 1}`" />
            <button type="button" class="text-rose-500" :aria-label="`Quitar condición ${i + 1}`" @click="form.conditions.splice(i, 1)">
              <AppIcon name="close" :size="14" />
            </button>
          </div>
        </fieldset>

        <!-- Acciones -->
        <fieldset>
          <div class="mb-1 flex items-center justify-between">
            <legend class="text-sm font-medium text-slate-700 dark:text-slate-300">Entonces (acciones)</legend>
            <button type="button" class="btn-ghost text-xs" @click="addAction"><AppIcon name="plus" :size="14" /> Acción</button>
          </div>
          <p class="mb-2 text-xs text-slate-500">
            <template v-if="freeFields">
              En los textos usa los campos del JSON recibido: <code class="rounded bg-slate-100 px-1 dark:bg-slate-800">{campo}</code> o
              <code class="rounded bg-slate-100 px-1 dark:bg-slate-800">{objeto.campo}</code>.
            </template>
            <template v-else>
              En los textos puedes usar:
              <code v-for="f in triggerFields" :key="f" class="mr-1 rounded bg-slate-100 px-1 dark:bg-slate-800">{{ '{' + f + '}' }}</code>
            </template>
          </p>
          <div v-for="(a, i) in form.actions" :key="i" class="mb-2 space-y-2 rounded-lg border border-slate-100 p-3 dark:border-slate-800">
            <div class="flex items-center gap-2">
              <select
                :value="a.type"
                class="input w-auto flex-1"
                :aria-label="`Tipo de la acción ${i + 1}`"
                @change="changeActionType(a, ($event.target as HTMLSelectElement).value)"
              >
                <option v-for="ac in availableActions" :key="ac.value" :value="ac.value">{{ ac.label }}</option>
              </select>
              <button
                v-if="form.actions.length > 1"
                type="button"
                class="text-rose-500"
                :aria-label="`Quitar acción ${i + 1}`"
                @click="form.actions.splice(i, 1)"
              >
                <AppIcon name="close" :size="14" />
              </button>
            </div>
            <p v-if="fieldError(`actions.${i}.type`)" class="text-xs text-rose-600">{{ fieldError(`actions.${i}.type`) }}</p>
            <p v-if="a.type === 'create_draft' && !form.brand" class="text-xs text-amber-600">Elige arriba la marca donde se crearán los borradores.</p>
            <div v-for="f in actionFields[a.type] ?? []" :key="f.key">
              <label :for="`action-${i}-${f.key}`" class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">{{ f.label }}</label>
              <select v-if="f.kind === 'audience'" :id="`action-${i}-${f.key}`" v-model="a.config[f.key]" class="input">
                <option v-for="au in meta?.audiences" :key="au.value" :value="au.value">{{ au.label }}</option>
              </select>
              <textarea
                v-else-if="f.kind === 'textarea'"
                :id="`action-${i}-${f.key}`"
                v-model="a.config[f.key]"
                rows="2"
                class="input"
                :placeholder="f.placeholder"
              />
              <input v-else :id="`action-${i}-${f.key}`" v-model="a.config[f.key]" class="input" :placeholder="f.placeholder" />
              <p v-if="fieldError(`actions.${i}.config.${f.key}`)" class="mt-1 text-xs text-rose-600">
                {{ fieldError(`actions.${i}.config.${f.key}`) }}
              </p>
            </div>
          </div>
        </fieldset>

        <!-- Historial -->
        <section v-if="form.id" aria-labelledby="auto-runs">
          <h3 id="auto-runs" class="mb-1 text-sm font-medium text-slate-700 dark:text-slate-300">Últimas ejecuciones</h3>
          <div v-if="runsLoading" class="skeleton h-16 w-full" />
          <p v-else-if="runs.length === 0" class="text-xs text-slate-400">Todavía no se ha ejecutado.</p>
          <ul v-else class="divide-y divide-slate-100 rounded-lg border border-slate-100 text-xs dark:divide-slate-800 dark:border-slate-800">
            <li v-for="r in runs" :key="r.id" class="flex flex-wrap items-center gap-2 px-3 py-2">
              <StatusBadge :tone="runTones[r.status]" dot>{{ runLabels[r.status] }}</StatusBadge>
              <span class="text-slate-400">{{ fmt(r.created_at) }}</span>
              <span class="min-w-0 flex-1 truncate text-slate-600 dark:text-slate-300" :title="r.message ?? ''">{{ r.message }}</span>
            </li>
          </ul>
        </section>
      </div>

      <div class="mt-6 flex justify-end gap-2">
        <button class="btn-secondary text-sm" @click="modalOpen = false">{{ form.id ? 'Cerrar' : 'Cancelar' }}</button>
        <button class="btn-primary text-sm" :disabled="saving" @click="save">{{ saving ? 'Guardando…' : 'Guardar' }}</button>
      </div>
    </ModalDialog>
  </div>
</template>
