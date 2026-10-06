<script setup lang="ts">
import { computed } from 'vue'
import { useFlowEditor } from '@/composables/useFlowEditor'
import type { FlowStep } from '@/types/automations'
import FlowConnector from '@/components/automations/FlowConnector.vue'
import FlowNode from '@/components/automations/FlowNode.vue'

/**
 * Una lista de pasos del diagrama, con su «+» entre pasos. Una condición es el
 * último paso de su lista y se abre en dos columnas («Sí» y «No»), cada una con
 * su propia lista (este mismo componente, de forma recursiva).
 */
const props = withDefaults(defineProps<{ steps: FlowStep[]; root?: boolean }>(), { root: false })

const editor = useFlowEditor()
const endsWithBranch = computed(() => props.steps[props.steps.length - 1]?.type === 'branch')

function taken(step: FlowStep, arm: 'yes' | 'no'): boolean | null {
  const entry = editor.overlay.value?.entries[step.id]
  return entry ? entry.path === arm : null
}
</script>

<template>
  <div class="flex flex-col items-center">
    <template v-for="(step, i) in steps" :key="step.id">
      <FlowConnector :list="steps" :index="i" />
      <FlowNode :step="step" />

      <!-- Caminos de una condición -->
      <div v-if="step.type === 'branch'" class="flex flex-col items-center">
        <div class="h-4 w-px bg-slate-300 dark:bg-slate-600" aria-hidden="true" />
        <div class="flex items-start">
          <div
            v-for="arm in (['yes', 'no'] as const)"
            :key="arm"
            class="relative flex flex-col items-center px-4"
          >
            <!-- Mitad de la línea horizontal que une las dos columnas -->
            <div
              class="absolute top-0 h-px bg-slate-300 dark:bg-slate-600"
              :class="arm === 'yes' ? 'left-1/2 right-0' : 'left-0 right-1/2'"
              aria-hidden="true"
            />
            <div class="h-4 w-px bg-slate-300 dark:bg-slate-600" aria-hidden="true" />
            <span
              class="rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset"
              :class="[
                arm === 'yes'
                  ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-950/40 dark:text-emerald-300'
                  : 'bg-slate-100 text-slate-600 ring-slate-500/20 dark:bg-slate-800 dark:text-slate-300',
                taken(step, arm) === true ? 'ring-2 ring-brand-500' : '',
                taken(step, arm) === false ? 'opacity-50' : '',
              ]"
            >
              {{ arm === 'yes' ? 'Sí' : 'No' }}
            </span>
            <FlowStepList :steps="step[arm]" />
          </div>
        </div>
      </div>
    </template>

    <template v-if="!endsWithBranch">
      <FlowConnector :list="steps" :index="steps.length" />
      <span
        class="inline-flex items-center gap-1 rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-medium text-slate-500 dark:border-slate-700 dark:bg-slate-900"
      >
        {{ root ? 'Fin' : steps.length === 0 ? 'No hace nada' : 'Fin del camino' }}
      </span>
    </template>
  </div>
</template>
