<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import { useFlowEditor } from '@/composables/useFlowEditor'
import type { ActionStep } from '@/types/automations'
import { ACTION_FIELDS } from '@/utils/automationFlow'
import VariableChips from '@/components/automations/VariableChips.vue'
import AppIcon from '@/components/AppIcon.vue'

/** Configuración de un paso «Acción». */
const props = defineProps<{ step: ActionStep }>()

const editor = useFlowEditor()
const inputs = ref<Record<string, HTMLInputElement | HTMLTextAreaElement | null>>({})
const lastField = ref<string | null>(null)

const available = computed(() => (editor.meta.value?.actions ?? []).filter((a) => a.triggers === null || a.triggers.includes(editor.form.trigger)))
const allowed = computed(() => available.value.some((a) => a.value === props.step.action))
const fields = computed(() => ACTION_FIELDS[props.step.action] ?? [])
const textFields = computed(() => fields.value.filter((f) => f.kind !== 'audience'))

function changeType(event: Event): void {
  const action = (event.target as HTMLSelectElement).value
  props.step.action = action
  props.step.config = action === 'notify' ? { message: '', audience: 'managers' } : {}
}

function setInput(key: string, el: unknown): void {
  inputs.value[key] = el as HTMLInputElement | HTMLTextAreaElement | null
}

// Inserta {campo} donde estaba el cursor en el último texto enfocado.
async function insert(token: string): Promise<void> {
  const key = lastField.value ?? textFields.value[0]?.key
  if (!key) return
  const el = inputs.value[key]
  const value = props.step.config[key] ?? ''
  const start = el?.selectionStart ?? value.length
  const end = el?.selectionEnd ?? value.length
  props.step.config[key] = value.slice(0, start) + token + value.slice(end)
  await nextTick()
  el?.focus()
  el?.setSelectionRange(start + token.length, start + token.length)
}
</script>

<template>
  <div class="space-y-4">
    <div>
      <label :for="`act-type-${step.id}`" class="label">Qué hacer</label>
      <select :id="`act-type-${step.id}`" :value="step.action" class="input" @change="changeType">
        <option v-if="!allowed" :value="step.action" disabled>{{ editor.meta.value?.actions.find((a) => a.value === step.action)?.label ?? step.action }}</option>
        <option v-for="a in available" :key="a.value" :value="a.value">{{ a.label }}</option>
      </select>
      <p v-if="!allowed" class="mt-1 flex items-center gap-1 text-xs text-amber-700 dark:text-amber-400">
        <AppIcon name="alert" :size="12" /> Esta acción no funciona con el disparador elegido.
      </p>
      <p v-if="editor.fieldError(step.id, 'action')" class="mt-1 text-xs text-rose-600">{{ editor.fieldError(step.id, 'action') }}</p>
      <p v-if="step.action === 'create_draft' && !editor.form.brand" class="mt-1 text-xs text-amber-700 dark:text-amber-400">
        Elige en el disparador la marca donde se crearán los borradores.
      </p>
    </div>

    <div v-for="f in fields" :key="f.key">
      <label :for="`act-${step.id}-${f.key}`" class="label">{{ f.label }}</label>
      <select v-if="f.kind === 'audience'" :id="`act-${step.id}-${f.key}`" v-model="step.config[f.key]" class="input">
        <option v-for="au in editor.meta.value?.audiences" :key="au.value" :value="au.value">{{ au.label }}</option>
      </select>
      <textarea
        v-else-if="f.kind === 'textarea'"
        :id="`act-${step.id}-${f.key}`"
        :ref="(el) => setInput(f.key, el)"
        v-model="step.config[f.key]"
        rows="3"
        class="input"
        :placeholder="f.placeholder"
        :aria-invalid="!!editor.fieldError(step.id, f.key)"
        @focus="lastField = f.key"
      />
      <input
        v-else
        :id="`act-${step.id}-${f.key}`"
        :ref="(el) => setInput(f.key, el)"
        v-model="step.config[f.key]"
        class="input"
        :class="f.key === 'url' ? 'font-mono text-xs' : ''"
        :placeholder="f.placeholder"
        :aria-invalid="!!editor.fieldError(step.id, f.key)"
        @focus="lastField = f.key"
      />
      <p v-if="editor.fieldError(step.id, f.key)" class="mt-1 text-xs text-rose-600">{{ editor.fieldError(step.id, f.key) }}</p>
    </div>

    <VariableChips v-if="textFields.length" :fields="editor.fields.value" :free="editor.form.trigger === 'webhook.received'" @pick="insert" />
    <p v-if="step.action === 'webhook'" class="text-xs text-slate-500">
      Se envía un POST con <code class="rounded bg-slate-100 px-1 dark:bg-slate-800">trigger</code> y
      <code class="rounded bg-slate-100 px-1 dark:bg-slate-800">context</code> (los datos del disparador). Sólo a servidores públicos.
    </p>
  </div>
</template>
