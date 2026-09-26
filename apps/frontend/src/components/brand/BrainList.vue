<script setup lang="ts" generic="T extends { id: string }">
import { reactive, ref } from 'vue'
import http from '@/services/http'
import { useToastStore } from '@/stores/toasts'
import { useConfirmStore } from '@/stores/confirm'
import { apiErrorMessage, apiValidationErrors } from '@/utils/errors'
import EmptyState from '@/components/ui/EmptyState.vue'
import Spinner from '@/components/ui/Spinner.vue'
import AppIcon from '@/components/AppIcon.vue'

export interface BrainField {
  key: string
  label: string
  type?: 'text' | 'textarea' | 'url' | 'select'
  required?: boolean
  placeholder?: string
  maxlength?: number
  options?: { value: string; label: string }[]
  /** Sólo se muestra (y se envía) si devuelve true para el borrador actual. */
  showIf?: (draft: Record<string, string>) => boolean
  /** Ocupa toda la fila del formulario. */
  wide?: boolean
}

/**
 * Lista editable de un elemento del Brand Brain (audiencias, productos,
 * servicios, conocimiento): crear, editar en línea y quitar con confirmación.
 */
const props = defineProps<{
  kind: 'audiences' | 'products' | 'services' | 'knowledge'
  brandId: string
  fields: BrainField[]
  canEdit: boolean
  addLabel: string
  itemLabel: (item: T) => string
  emptyTitle: string
  emptyDescription: string
  emptyIcon?: string
}>()

const items = defineModel<T[]>('items', { required: true })

const toasts = useToastStore()
const confirmDialog = useConfirmStore()

function blank(): Record<string, string> {
  return Object.fromEntries(props.fields.map((f) => [f.key, f.type === 'select' ? (f.options?.[0]?.value ?? '') : '']))
}

const draft = reactive<Record<string, string>>(blank())
const createErrors = ref<Record<string, string[]>>({})
const creating = ref(false)

const editingId = ref<string | null>(null)
const editDraft = reactive<Record<string, string>>({})
const editErrors = ref<Record<string, string[]>>({})
const saving = ref(false)

function visible(field: BrainField, values: Record<string, string>): boolean {
  return field.showIf ? field.showIf(values) : true
}

/** Vacío u oculto → null, para que el backend lo borre (p. ej. al cambiar de tipo). */
function payload(values: Record<string, string>): Record<string, string | null> {
  return Object.fromEntries(
    props.fields.map((f) => [f.key, visible(f, values) && values[f.key]?.trim() ? values[f.key].trim() : null]),
  )
}

async function create(): Promise<void> {
  creating.value = true
  createErrors.value = {}
  try {
    const { data } = await http.post(`/brands/${props.brandId}/${props.kind}`, payload(draft))
    items.value = [data.data as T, ...items.value]
    Object.assign(draft, blank())
    toasts.success('Añadido.')
  } catch (e) {
    createErrors.value = apiValidationErrors(e)
    if (!Object.keys(createErrors.value).length) toasts.error(apiErrorMessage(e))
  } finally {
    creating.value = false
  }
}

function startEdit(item: T): void {
  const values = item as unknown as Record<string, unknown>
  for (const key of Object.keys(editDraft)) delete editDraft[key]
  for (const f of props.fields) editDraft[f.key] = values[f.key] == null ? '' : String(values[f.key])
  editErrors.value = {}
  editingId.value = item.id
}

async function saveEdit(): Promise<void> {
  if (!editingId.value) return
  saving.value = true
  editErrors.value = {}
  try {
    const { data } = await http.patch(`/brands/${props.brandId}/${props.kind}/${editingId.value}`, payload(editDraft))
    items.value = items.value.map((i) => (i.id === editingId.value ? (data.data as T) : i))
    editingId.value = null
    toasts.success('Cambios guardados.')
  } catch (e) {
    editErrors.value = apiValidationErrors(e)
    if (!Object.keys(editErrors.value).length) toasts.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

async function remove(item: T): Promise<void> {
  const ok = await confirmDialog.ask({
    title: `Quitar «${props.itemLabel(item)}»`,
    message: 'La IA dejará de usarlo al generar contenido de esta marca.',
    confirmText: 'Quitar',
    danger: true,
  })
  if (!ok) return
  try {
    await http.delete(`/brands/${props.brandId}/${props.kind}/${item.id}`)
    items.value = items.value.filter((i) => i.id !== item.id)
    if (editingId.value === item.id) editingId.value = null
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

function fieldId(prefix: string, key: string): string {
  return `${props.kind}-${prefix}-${key}`
}
</script>

<template>
  <div class="space-y-3">
    <form v-if="canEdit" class="card grid grid-cols-1 gap-3 p-4 sm:grid-cols-2" @submit.prevent="create">
      <template v-for="f in fields" :key="f.key">
        <div v-if="visible(f, draft)" :class="f.wide || f.type === 'textarea' ? 'sm:col-span-2' : ''">
          <label class="label" :for="fieldId('new', f.key)">
            {{ f.label }} <span v-if="!f.required && f.type !== 'select'" class="text-slate-400">(opcional)</span>
          </label>
          <select v-if="f.type === 'select'" :id="fieldId('new', f.key)" v-model="draft[f.key]" class="input">
            <option v-for="o in f.options" :key="o.value" :value="o.value">{{ o.label }}</option>
          </select>
          <textarea
            v-else-if="f.type === 'textarea'"
            :id="fieldId('new', f.key)"
            v-model="draft[f.key]"
            rows="2"
            class="input"
            :maxlength="f.maxlength"
            :placeholder="f.placeholder"
          />
          <input
            v-else
            :id="fieldId('new', f.key)"
            v-model="draft[f.key]"
            :type="f.type === 'url' ? 'url' : 'text'"
            class="input"
            :required="f.required"
            :maxlength="f.maxlength"
            :placeholder="f.placeholder"
          />
          <p v-if="createErrors[f.key]" class="mt-1 text-xs text-rose-600">{{ createErrors[f.key][0] }}</p>
        </div>
      </template>
      <div class="flex justify-end sm:col-span-2">
        <button type="submit" class="btn-primary text-sm" :disabled="creating">
          <Spinner v-if="creating" :size="16" /> <AppIcon v-else name="plus" :size="16" /> {{ addLabel }}
        </button>
      </div>
    </form>

    <EmptyState v-if="items.length === 0" :icon="emptyIcon ?? 'sparkles'" :title="emptyTitle" :description="emptyDescription" />

    <div v-for="item in items" :key="item.id" class="card p-4">
      <form v-if="editingId === item.id" class="grid grid-cols-1 gap-3 sm:grid-cols-2" @submit.prevent="saveEdit">
        <template v-for="f in fields" :key="f.key">
          <div v-if="visible(f, editDraft)" :class="f.wide || f.type === 'textarea' ? 'sm:col-span-2' : ''">
            <label class="label" :for="fieldId(item.id, f.key)">{{ f.label }}</label>
            <select v-if="f.type === 'select'" :id="fieldId(item.id, f.key)" v-model="editDraft[f.key]" class="input">
              <option v-for="o in f.options" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
            <textarea
              v-else-if="f.type === 'textarea'"
              :id="fieldId(item.id, f.key)"
              v-model="editDraft[f.key]"
              rows="3"
              class="input"
              :maxlength="f.maxlength"
            />
            <input
              v-else
              :id="fieldId(item.id, f.key)"
              v-model="editDraft[f.key]"
              :type="f.type === 'url' ? 'url' : 'text'"
              class="input"
              :required="f.required"
              :maxlength="f.maxlength"
            />
            <p v-if="editErrors[f.key]" class="mt-1 text-xs text-rose-600">{{ editErrors[f.key][0] }}</p>
          </div>
        </template>
        <div class="flex justify-end gap-2 sm:col-span-2">
          <button type="button" class="btn-secondary text-sm" @click="editingId = null">Cancelar</button>
          <button type="submit" class="btn-primary text-sm" :disabled="saving">
            <Spinner v-if="saving" :size="16" /> Guardar
          </button>
        </div>
      </form>

      <div v-else class="flex items-start justify-between gap-3">
        <div class="min-w-0 flex-1">
          <slot name="item" :item="item" />
        </div>
        <div v-if="canEdit" class="flex shrink-0 gap-1">
          <button type="button" class="btn-ghost px-2 py-1 text-xs" :aria-label="`Editar ${itemLabel(item)}`" @click="startEdit(item)">
            Editar
          </button>
          <button type="button" class="btn-ghost px-2 py-1 text-rose-600" :aria-label="`Quitar ${itemLabel(item)}`" @click="remove(item)">
            <AppIcon name="close" :size="16" />
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
