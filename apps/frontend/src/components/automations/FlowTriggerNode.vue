<script setup lang="ts">
import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useFlowEditor } from '@/composables/useFlowEditor'
import AppIcon from '@/components/AppIcon.vue'

/** Primer nodo del diagrama: cuándo se ejecuta la automatización. */
const editor = useFlowEditor()
const auth = useAuthStore()

const selected = computed(() => editor.selected.value === 'trigger')
const trigger = computed(() => editor.meta.value?.triggers.find((t) => t.value === editor.form.trigger))
const brandName = computed(() => auth.brands.find((b) => b.id === editor.form.brand)?.name ?? null)
const hasError = computed(() => Object.keys(editor.errors.value).some((k) => k.startsWith('trigger_config')))

const detail = computed(() => {
  if (editor.form.trigger === 'rss.item_published') {
    try {
      return editor.form.feed_url ? `Feed: ${new URL(editor.form.feed_url).host}` : 'Falta la URL del feed'
    } catch {
      return 'Revisa la URL del feed'
    }
  }
  if (editor.form.trigger === 'webhook.received') return 'Con una URL secreta'
  return brandName.value ? `Marca: ${brandName.value}` : 'En todas las marcas'
})
</script>

<template>
  <button
    type="button"
    class="w-72 rounded-xl border bg-white p-3 text-left shadow-sm transition hover:border-brand-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 dark:bg-slate-900"
    :class="selected ? 'border-brand-500 ring-2 ring-brand-200 dark:ring-brand-900' : hasError ? 'border-rose-300' : 'border-slate-200 dark:border-slate-700'"
    :aria-pressed="selected"
    :aria-label="`Disparador: ${trigger?.label ?? ''}. ${detail}`"
    data-step="trigger"
    @click="editor.select('trigger')"
  >
    <span class="flex items-start gap-3">
      <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-slate-900 text-white dark:bg-white dark:text-slate-900">
        <AppIcon name="bolt" :size="16" />
      </span>
      <span class="min-w-0 flex-1">
        <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">Disparador</span>
        <span class="block text-sm font-semibold text-slate-800 dark:text-slate-100">{{ trigger?.label ?? 'Elige cuándo se ejecuta' }}</span>
        <span class="block truncate text-xs" :class="hasError ? 'text-rose-600' : 'text-slate-500'">{{ detail }}</span>
      </span>
    </span>
  </button>
</template>
