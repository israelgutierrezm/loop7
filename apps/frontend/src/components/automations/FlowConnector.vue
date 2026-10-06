<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { useFlowEditor } from '@/composables/useFlowEditor'
import type { FlowStep, StepType } from '@/types/automations'
import { containsList, locate } from '@/utils/automationFlow'
import AppIcon from '@/components/AppIcon.vue'

/**
 * Línea entre dos pasos con el botón «+» para añadir uno ahí; mientras se
 * arrastra un paso, también es una zona donde soltarlo.
 */
const props = defineProps<{ list: FlowStep[]; index: number }>()

const editor = useFlowEditor()
const open = ref(false)
const over = ref(false)
const root = ref<HTMLElement | null>(null)

const options: { type: StepType; label: string; hint: string; icon: string }[] = [
  { type: 'action', label: 'Acción', hint: 'Avisar, responder, crear un borrador, llamar un webhook…', icon: 'bolt' },
  { type: 'branch', label: 'Condición', hint: 'Seguir por «Sí» o por «No» según los datos.', icon: 'branch' },
  { type: 'wait', label: 'Esperar', hint: 'Minutos, horas o días antes del paso siguiente.', icon: 'clock' },
]

// Se puede soltar aquí salvo que sea dentro de la propia condición que se arrastra.
const droppable = computed(() => {
  const id = editor.dragging.value
  if (!id) return false
  const location = locate(editor.flow.steps, id)
  return location !== null && !(location.step.type === 'branch' && containsList(location.step, props.list))
})

function choose(type: StepType): void {
  open.value = false
  editor.add(props.list, props.index, type)
}

function onDrop(): void {
  over.value = false
  if (droppable.value) editor.drop(props.list, props.index)
}

function onDocumentClick(event: MouseEvent): void {
  if (root.value && !root.value.contains(event.target as Node)) open.value = false
}

function toggle(): void {
  open.value = !open.value
  if (open.value) document.addEventListener('click', onDocumentClick)
  else document.removeEventListener('click', onDocumentClick)
}

onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick))
</script>

<template>
  <div ref="root" class="relative flex w-72 flex-col items-center" @keydown.esc="open = false">
    <div class="h-4 w-px bg-slate-300 dark:bg-slate-600" aria-hidden="true" />

    <div
      v-if="droppable"
      class="my-1 flex h-9 w-full items-center justify-center rounded-lg border-2 border-dashed text-xs font-medium transition"
      :class="over ? 'border-brand-500 bg-brand-50 text-brand-700 dark:bg-brand-950/40 dark:text-brand-300' : 'border-slate-300 text-slate-400 dark:border-slate-600'"
      @dragover.prevent="over = true"
      @dragleave="over = false"
      @drop.prevent="onDrop"
    >
      Soltar aquí
    </div>
    <button
      v-else
      type="button"
      class="grid h-7 w-7 place-items-center rounded-full border border-slate-300 bg-white text-slate-500 shadow-sm transition hover:border-brand-400 hover:text-brand-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-400"
      :aria-expanded="open"
      aria-haspopup="menu"
      aria-label="Añadir un paso aquí"
      title="Añadir un paso aquí"
      @click.stop="toggle"
    >
      <AppIcon name="plus" :size="14" />
    </button>

    <div class="h-4 w-px bg-slate-300 dark:bg-slate-600" aria-hidden="true" />

    <div
      v-if="open"
      role="menu"
      class="absolute top-12 z-20 w-72 rounded-xl border border-slate-200 bg-white p-1 shadow-lg dark:border-slate-700 dark:bg-slate-900"
    >
      <button
        v-for="o in options"
        :key="o.type"
        type="button"
        role="menuitem"
        class="flex w-full items-start gap-3 rounded-lg px-3 py-2 text-left hover:bg-slate-50 focus-visible:bg-slate-50 focus-visible:outline-none disabled:opacity-50 dark:hover:bg-slate-800 dark:focus-visible:bg-slate-800"
        :disabled="!editor.canAdd(o.type)"
        @click="choose(o.type)"
      >
        <AppIcon :name="o.icon" :size="16" class="mt-0.5 text-brand-600" />
        <span class="min-w-0">
          <span class="block text-sm font-medium text-slate-800 dark:text-slate-100">{{ o.label }}</span>
          <span class="block text-xs text-slate-500">{{ o.hint }}</span>
        </span>
      </button>
    </div>
  </div>
</template>
