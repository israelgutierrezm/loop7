<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useFlowEditor } from '@/composables/useFlowEditor'
import FlowStepList from '@/components/automations/FlowStepList.vue'
import FlowTriggerNode from '@/components/automations/FlowTriggerNode.vue'
import AppIcon from '@/components/AppIcon.vue'

/** Diagrama vertical del flujo: disparador, pasos y caminos de las condiciones. */
const editor = useFlowEditor()

const STORAGE_KEY = 'loop7.automationZoom'
const MIN = 0.5
const MAX = 1.25
const scroller = ref<HTMLElement | null>(null)
const content = ref<HTMLElement | null>(null)
const zoom = ref(1)

function clamp(value: number): number {
  return Math.min(MAX, Math.max(MIN, Math.round(value * 100) / 100))
}

function zoomBy(delta: number): void {
  zoom.value = clamp(zoom.value + delta)
}

// Que quepa a lo ancho (sin pasar del 100 %).
function fit(): void {
  if (!scroller.value || !content.value) return
  // El tamaño en pantalla ya incluye el zoom actual: se deshace para obtener el natural.
  const natural = content.value.getBoundingClientRect().width / zoom.value
  zoom.value = clamp(Math.min(1, scroller.value.clientWidth / Math.max(natural, 1)))
}

// Preferencia de cada persona en este navegador (puede no haber almacenamiento).
onMounted(() => {
  try {
    const saved = Number(localStorage.getItem(STORAGE_KEY))
    if (saved >= MIN && saved <= MAX) zoom.value = saved
  } catch {
    // Sin almacenamiento: 100 %.
  }
})
watch(zoom, (value) => {
  try {
    localStorage.setItem(STORAGE_KEY, String(value))
  } catch {
    // Sin almacenamiento: no se recuerda.
  }
})
</script>

<template>
  <div class="relative">
    <div
      class="absolute right-2 top-2 z-10 flex items-center gap-0.5 rounded-lg border border-slate-200 bg-white/90 p-0.5 shadow-sm backdrop-blur dark:border-slate-700 dark:bg-slate-900/90"
      role="group"
      aria-label="Zoom del diagrama"
    >
      <button type="button" class="btn-ghost p-1" :disabled="zoom <= MIN" aria-label="Alejar" title="Alejar" @click="zoomBy(-0.1)">
        <AppIcon name="minus" :size="14" />
      </button>
      <button
        type="button"
        class="btn-ghost px-1.5 py-1 text-xs tabular-nums"
        :aria-label="`Zoom ${Math.round(zoom * 100)} %. Ajustar al ancho`"
        title="Ajustar al ancho"
        @click="fit"
      >
        {{ Math.round(zoom * 100) }}%
      </button>
      <button type="button" class="btn-ghost p-1" :disabled="zoom >= MAX" aria-label="Acercar" title="Acercar" @click="zoomBy(0.1)">
        <AppIcon name="plus" :size="14" />
      </button>
    </div>

    <div ref="scroller" class="overflow-x-auto" role="region" aria-label="Diagrama de la automatización" tabindex="0">
      <div class="flex w-max min-w-full justify-center">
        <div ref="content" class="flex flex-col items-center px-6 pb-10 pr-16 pt-12" :style="{ zoom }">
          <FlowTriggerNode />
          <FlowStepList :steps="editor.flow.steps" root />
        </div>
      </div>
    </div>
  </div>
</template>
