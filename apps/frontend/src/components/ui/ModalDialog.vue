<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, useId, watch } from 'vue'
import AppIcon from '@/components/AppIcon.vue'

const props = withDefaults(
  defineProps<{
    open: boolean
    title: string
    description?: string
    size?: 'sm' | 'md' | 'lg' | 'xl'
  }>(),
  { description: '', size: 'md' },
)
const emit = defineEmits<{ close: [] }>()

const widths: Record<string, string> = {
  sm: 'max-w-md',
  md: 'max-w-lg',
  lg: 'max-w-2xl',
  xl: 'max-w-4xl',
}

const titleId = useId()
const panel = ref<HTMLElement | null>(null)
let previouslyFocused: HTMLElement | null = null

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

function focusables(): HTMLElement[] {
  return panel.value ? Array.from(panel.value.querySelectorAll<HTMLElement>(FOCUSABLE)) : []
}

// Esc cierra y Tab queda atrapado dentro del diálogo (WCAG 2.1.2 / 2.4.3).
function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    event.stopPropagation()
    emit('close')
    return
  }
  if (event.key !== 'Tab') return
  const items = focusables()
  if (items.length === 0) return
  const first = items[0]
  const last = items[items.length - 1]
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault()
    first.focus()
  }
}

watch(
  () => props.open,
  async (open) => {
    if (open) {
      previouslyFocused = document.activeElement as HTMLElement | null
      document.body.style.overflow = 'hidden'
      await nextTick()
      // Primer campo del contenido (no el botón de cerrar), o el panel.
      const first = focusables().find((el) => !el.dataset.modalClose)
      ;(first ?? panel.value)?.focus()
    } else {
      document.body.style.overflow = ''
      previouslyFocused?.focus?.()
    }
  },
  { immediate: true },
)

onBeforeUnmount(() => {
  document.body.style.overflow = ''
})
</script>

<template>
  <Teleport to="body">
    <Transition
      enter-active-class="transition-opacity duration-150"
      enter-from-class="opacity-0"
      leave-active-class="transition-opacity duration-100"
      leave-to-class="opacity-0"
    >
      <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center p-4" @keydown="onKeydown">
        <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-[1px]" aria-hidden="true" @click="emit('close')" />
        <div
          ref="panel"
          role="dialog"
          aria-modal="true"
          :aria-labelledby="titleId"
          tabindex="-1"
          class="card relative z-10 flex max-h-[90vh] w-full flex-col outline-none"
          :class="widths[size]"
        >
          <div class="flex items-start justify-between gap-4 border-b border-slate-100 px-6 py-4 dark:border-slate-800">
            <div class="min-w-0">
              <h2 :id="titleId" class="text-lg font-semibold text-slate-900 dark:text-white">{{ title }}</h2>
              <p v-if="description" class="mt-0.5 text-sm text-slate-500">{{ description }}</p>
            </div>
            <button
              type="button"
              data-modal-close="1"
              class="grid h-8 w-8 shrink-0 place-items-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800"
              aria-label="Cerrar"
              @click="emit('close')"
            >
              <AppIcon name="close" :size="18" />
            </button>
          </div>
          <div class="overflow-y-auto px-6 py-5">
            <slot />
          </div>
          <div
            v-if="$slots.footer"
            class="flex flex-wrap items-center justify-end gap-2 border-t border-slate-100 px-6 py-3 dark:border-slate-800"
          >
            <slot name="footer" />
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
