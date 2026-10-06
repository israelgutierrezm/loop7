<script setup lang="ts">
import { computed, useId } from 'vue'
import { useFlowEditor } from '@/composables/useFlowEditor'
import type { BranchStep } from '@/types/automations'
import AppIcon from '@/components/AppIcon.vue'

/** Configuración de un paso «Condición»: qué se compara y cómo se combinan. */
const props = defineProps<{ step: BranchStep }>()

const editor = useFlowEditor()
const listId = useId()

const max = computed(() => editor.meta.value?.limits.max_conditions ?? 10)

function needsValue(operator: string): boolean {
  return editor.meta.value?.operators.find((o) => o.value === operator)?.needs_value ?? true
}

function add(): void {
  props.step.conditions.push({ field: editor.fields.value[0] ?? '', operator: 'contains', value: '' })
}
</script>

<template>
  <div class="space-y-4">
    <p class="text-xs text-slate-500">Si se cumple, sigue por «Sí»; si no, por «No». Cada camino tiene sus propios pasos.</p>

    <fieldset>
      <legend class="label">Cómo se combinan</legend>
      <div class="flex flex-wrap gap-3">
        <label v-for="m in editor.meta.value?.matches" :key="m.value" class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
          <input v-model="step.match" type="radio" :name="`match-${step.id}`" :value="m.value" class="text-brand-600 focus:ring-brand-500" />
          {{ m.label }}
        </label>
      </div>
    </fieldset>

    <fieldset class="space-y-3">
      <legend class="label">Condiciones</legend>
      <datalist :id="listId">
        <option v-for="f in editor.fields.value" :key="f" :value="f" />
      </datalist>
      <div v-for="(c, i) in step.conditions" :key="i" class="space-y-2 rounded-lg border border-slate-200 p-2.5 dark:border-slate-700">
        <div class="flex items-center justify-between">
          <span class="text-xs font-medium text-slate-500">{{ i === 0 ? 'Si' : step.match === 'any' ? 'O si' : 'Y si' }}</span>
          <button
            v-if="step.conditions.length > 1"
            type="button"
            class="btn-ghost p-1 text-rose-600"
            :aria-label="`Quitar condición ${i + 1}`"
            @click="step.conditions.splice(i, 1)"
          >
            <AppIcon name="close" :size="14" />
          </button>
        </div>
        <input
          v-model="c.field"
          :list="listId"
          class="input font-mono text-xs"
          placeholder="campo (p. ej. text o cliente.email)"
          :aria-label="`Campo de la condición ${i + 1}`"
          :aria-invalid="!!editor.fieldError(step.id, `conditions.${i}.field`)"
        />
        <p v-if="editor.fieldError(step.id, `conditions.${i}.field`)" class="text-xs text-rose-600">{{ editor.fieldError(step.id, `conditions.${i}.field`) }}</p>
        <select v-model="c.operator" class="input" :aria-label="`Operador de la condición ${i + 1}`">
          <option v-for="o in editor.meta.value?.operators" :key="o.value" :value="o.value">{{ o.label }}</option>
        </select>
        <input
          v-if="needsValue(c.operator)"
          v-model="c.value"
          class="input"
          placeholder="valor"
          :aria-label="`Valor de la condición ${i + 1}`"
        />
      </div>
      <p v-if="editor.fieldError(step.id, 'conditions')" class="text-xs text-rose-600">{{ editor.fieldError(step.id, 'conditions') }}</p>
      <button type="button" class="btn-ghost text-xs" :disabled="step.conditions.length >= max" @click="add">
        <AppIcon name="plus" :size="14" /> Añadir condición
      </button>
    </fieldset>
    <p class="text-xs text-slate-500">Las comparaciones no distinguen mayúsculas de minúsculas.</p>
  </div>
</template>
