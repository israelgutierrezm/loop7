<script setup lang="ts">
import { useFlowEditor } from '@/composables/useFlowEditor'
import type { WaitStep } from '@/types/automations'

/** Configuración de un paso «Esperar». */
defineProps<{ step: WaitStep }>()

const editor = useFlowEditor()
</script>

<template>
  <div class="space-y-4">
    <fieldset>
      <legend class="label">Esperar antes del paso siguiente</legend>
      <div class="flex gap-2">
        <input
          :id="`wait-${step.id}`"
          v-model.number="step.amount"
          type="number"
          min="1"
          inputmode="numeric"
          class="input w-24"
          aria-label="Cantidad"
          :aria-invalid="!!editor.fieldError(step.id, 'amount')"
        />
        <select v-model="step.unit" class="input flex-1" aria-label="Unidad">
          <option v-for="u in editor.meta.value?.wait_units" :key="u.value" :value="u.value">{{ u.label }}</option>
        </select>
      </div>
      <p v-if="editor.fieldError(step.id, 'amount')" class="mt-1 text-xs text-rose-600">{{ editor.fieldError(step.id, 'amount') }}</p>
    </fieldset>
    <p class="text-xs text-slate-500">
      Máximo {{ editor.meta.value?.limits.max_wait_days ?? 30 }} días. La ejecución queda «En espera» y sigue sola al vencer.
      Si mientras tanto pausas la automatización o quitas el paso siguiente, se cancela.
    </p>
  </div>
</template>
