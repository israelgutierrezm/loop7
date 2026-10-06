<script setup lang="ts">
import { computed } from 'vue'
import { useFlowEditor } from '@/composables/useFlowEditor'
import type { FlowStep } from '@/types/automations'
import { stepSummary, stepTitle } from '@/utils/automationFlow'
import AppIcon from '@/components/AppIcon.vue'
import TraceBadge from '@/components/automations/TraceBadge.vue'

/** Tarjeta de un paso del diagrama (acción, espera o condición). */
const props = defineProps<{ step: FlowStep }>()

const editor = useFlowEditor()

const selected = computed(() => editor.selected.value === props.step.id)
const errors = computed(() => editor.stepErrors(props.step.id))
const trace = computed(() => editor.overlay.value?.entries[props.step.id] ?? null)
// Con una traza encima, lo que no se recorrió queda atenuado.
const dimmed = computed(() => editor.overlay.value !== null && trace.value === null)

const kind = computed(() => ({ action: 'Acción', wait: 'Esperar', branch: 'Condición' })[props.step.type])
const icon = computed(() => ({ action: 'bolt', wait: 'clock', branch: 'branch' })[props.step.type])
const tone = computed(() => ({
  action: 'bg-brand-50 text-brand-600 dark:bg-brand-950/50',
  wait: 'bg-amber-50 text-amber-600 dark:bg-amber-950/40',
  branch: 'bg-violet-50 text-violet-600 dark:bg-violet-950/40',
})[props.step.type])

const title = computed(() => stepTitle(props.step, editor.meta.value))
const summary = computed(() => stepSummary(props.step, editor.meta.value))
const canDuplicate = computed(() => props.step.type !== 'branch')

function onDragStart(event: DragEvent): void {
  editor.dragging.value = props.step.id
  event.dataTransfer?.setData('text/plain', props.step.id)
  if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move'
}
</script>

<template>
  <div class="group relative w-72" :class="dimmed ? 'opacity-50' : ''" :data-step="step.id">
    <button
      type="button"
      draggable="true"
      class="w-full rounded-xl border bg-white p-3 text-left shadow-sm transition hover:border-brand-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 dark:bg-slate-900"
      :class="[
        selected ? 'border-brand-500 ring-2 ring-brand-200 dark:ring-brand-900' : errors.length ? 'border-rose-300 dark:border-rose-800' : 'border-slate-200 dark:border-slate-700',
        editor.dragging.value === step.id ? 'opacity-40' : '',
      ]"
      :aria-pressed="selected"
      :aria-label="title === kind ? `${kind}. ${summary}` : `${kind}: ${title}. ${summary}`"
      @click="editor.select(step.id)"
      @dragstart="onDragStart"
      @dragend="editor.dragging.value = null"
    >
      <span class="flex items-start gap-3">
        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg" :class="tone">
          <AppIcon :name="icon" :size="16" />
        </span>
        <span class="min-w-0 flex-1">
          <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ kind }}</span>
          <span class="block truncate text-sm font-semibold text-slate-800 dark:text-slate-100">{{ title }}</span>
          <span class="block truncate text-xs text-slate-500">{{ summary }}</span>
        </span>
      </span>
      <span v-if="errors.length" class="mt-2 flex items-start gap-1 text-xs text-rose-600">
        <AppIcon name="alert" :size="12" class="mt-0.5 shrink-0" /> {{ errors[0] }}
      </span>
      <TraceBadge v-if="trace" :entry="trace" class="mt-2" />
    </button>

    <!-- Herramientas: visibles al pasar el ratón, al enfocar o con el paso seleccionado -->
    <div
      class="absolute left-full top-1 ml-1.5 flex flex-col gap-1 transition group-hover:opacity-100 group-focus-within:opacity-100"
      :class="selected ? 'opacity-100' : 'opacity-0'"
    >
      <template v-if="step.type !== 'branch'">
        <button type="button" class="btn-ghost p-1" :disabled="!editor.canMoveStep(step.id, -1)" :aria-label="`Subir: ${title}`" title="Subir" @click="editor.move(step.id, -1)">
          <AppIcon name="arrow-up" :size="14" />
        </button>
        <button type="button" class="btn-ghost p-1" :disabled="!editor.canMoveStep(step.id, 1)" :aria-label="`Bajar: ${title}`" title="Bajar" @click="editor.move(step.id, 1)">
          <AppIcon name="arrow-down" :size="14" />
        </button>
      </template>
      <button v-if="canDuplicate" type="button" class="btn-ghost p-1" :disabled="!editor.canAdd(step.type)" :aria-label="`Duplicar: ${title}`" title="Duplicar" @click="editor.duplicate(step.id)">
        <AppIcon name="copy" :size="14" />
      </button>
      <button type="button" class="btn-ghost p-1 text-rose-600" :aria-label="`Quitar: ${title}`" title="Quitar" @click="editor.remove(step.id)">
        <AppIcon name="trash" :size="14" />
      </button>
    </div>
  </div>
</template>
