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
import AppIcon from '@/components/AppIcon.vue'

interface Condition { field: string; operator: string; value: string }
interface ActionRow { type: string; config: Record<string, string> }
interface Automation {
  id: string
  name: string
  is_enabled: boolean
  trigger: string
  trigger_label: string
  brand: string | null
  brand_name: string | null
  conditions: Condition[]
  actions: ActionRow[]
  run_count: number
  last_run_at: string | null
}
interface Meta {
  triggers: { value: string; label: string; fields: string[] }[]
  actions: { value: string; label: string }[]
  operators: { value: string; label: string }[]
  audiences: { value: string; label: string }[]
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

const newAction = (type = 'notify'): ActionRow => ({ type, config: type === 'notify' ? { audience: 'managers' } : {} })
const blank = () => ({
  id: '' as string,
  name: '',
  trigger: 'content.published',
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
  inbox_reply: [{ key: 'message', label: 'Respuesta automática', placeholder: 'Ej. Hola {participant}, gracias por escribir.', kind: 'textarea' }],
  inbox_tag: [{ key: 'tag', label: 'Etiqueta', placeholder: 'Ej. urgente', kind: 'text' }],
}

const triggerFields = computed(() => meta.value?.triggers.find((t) => t.value === form.trigger)?.fields ?? [])

function fieldError(path: string): string | undefined {
  return errors.value[path]?.[0]
}

function changeActionType(action: ActionRow, type: string): void {
  Object.assign(action, newAction(type))
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
  errors.value = {}
  modalOpen.value = true
}

function openEdit(a: Automation): void {
  Object.assign(form, {
    id: a.id,
    name: a.name,
    trigger: a.trigger,
    brand: a.brand ?? '',
    is_enabled: a.is_enabled,
    conditions: a.conditions.map((c) => ({ ...c })),
    actions: a.actions.map((ac) => ({ type: ac.type, config: { ...ac.config } })),
  })
  errors.value = {}
  modalOpen.value = true
}

function addCondition(): void {
  form.conditions.push({ field: triggerFields.value[0] ?? '', operator: 'equals', value: '' })
}
function addAction(): void {
  form.actions.push(newAction())
}

async function save(): Promise<void> {
  if (!form.name.trim() || form.actions.length === 0) {
    toasts.error('Añade un nombre y al menos una acción.')
    return
  }
  saving.value = true
  errors.value = {}
  const payload = {
    name: form.name,
    trigger: form.trigger,
    brand: form.brand || null,
    is_enabled: form.is_enabled,
    conditions: form.conditions,
    actions: form.actions.map((a) => ({ type: a.type, config: a.config })),
  }
  try {
    if (form.id) {
      await http.put(`/automations/${form.id}`, payload)
    } else {
      await http.post('/automations', payload)
    }
    toasts.success('Automatización guardada.')
    modalOpen.value = false
    await load()
  } catch (e) {
    errors.value = apiValidationErrors(e)
    toasts.error(Object.keys(errors.value).length ? 'Revisa los campos marcados.' : apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

async function toggle(a: Automation): Promise<void> {
  try {
    await http.put(`/automations/${a.id}`, {
      name: a.name,
      trigger: a.trigger,
      brand: a.brand,
      is_enabled: !a.is_enabled,
      conditions: a.conditions,
      actions: a.actions,
    })
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
      description="Crea tu primera regla para ahorrar trabajo repetitivo."
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
    <ModalDialog :open="modalOpen" :title="form.id ? 'Editar automatización' : 'Nueva automatización'" @close="modalOpen = false">
      <div class="max-h-[70vh] space-y-4 overflow-y-auto pr-1">
        <div>
          <label for="auto-name" class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">Nombre</label>
          <input id="auto-name" v-model="form.name" class="input" placeholder="Ej. Avisar al equipo al publicar" />
          <p v-if="fieldError('name')" class="mt-1 text-xs text-rose-600">{{ fieldError('name') }}</p>
        </div>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div>
            <label for="auto-trigger" class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">Cuando… (disparador)</label>
            <select id="auto-trigger" v-model="form.trigger" class="input">
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

        <!-- Condiciones -->
        <fieldset>
          <div class="mb-1 flex items-center justify-between">
            <legend class="text-sm font-medium text-slate-700 dark:text-slate-300">Si se cumple (opcional)</legend>
            <button type="button" class="btn-ghost text-xs" @click="addCondition"><AppIcon name="plus" :size="14" /> Condición</button>
          </div>
          <div v-for="(c, i) in form.conditions" :key="i" class="mb-2 flex flex-wrap items-center gap-2">
            <select v-model="c.field" class="input w-auto flex-1" :aria-label="`Campo de la condición ${i + 1}`">
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
            En los textos puedes usar:
            <code v-for="f in triggerFields" :key="f" class="mr-1 rounded bg-slate-100 px-1 dark:bg-slate-800">{{ '{' + f + '}' }}</code>
          </p>
          <div v-for="(a, i) in form.actions" :key="i" class="mb-2 space-y-2 rounded-lg border border-slate-100 p-3 dark:border-slate-800">
            <div class="flex items-center gap-2">
              <select
                :value="a.type"
                class="input w-auto flex-1"
                :aria-label="`Tipo de la acción ${i + 1}`"
                @change="changeActionType(a, ($event.target as HTMLSelectElement).value)"
              >
                <option v-for="ac in meta?.actions" :key="ac.value" :value="ac.value">{{ ac.label }}</option>
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
      </div>

      <div class="mt-6 flex justify-end gap-2">
        <button class="btn-secondary text-sm" @click="modalOpen = false">Cancelar</button>
        <button class="btn-primary text-sm" :disabled="saving" @click="save">{{ saving ? 'Guardando…' : 'Guardar' }}</button>
      </div>
    </ModalDialog>
  </div>
</template>
