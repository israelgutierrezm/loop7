<script setup lang="ts">
export type BadgeTone = 'success' | 'warning' | 'danger' | 'info' | 'neutral' | 'brand'

withDefaults(defineProps<{ tone?: BadgeTone; dot?: boolean }>(), { tone: 'neutral', dot: false })

const tones: Record<BadgeTone, string> = {
  success: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-950/40 dark:text-emerald-300',
  warning: 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-950/40 dark:text-amber-300',
  danger: 'bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-950/40 dark:text-rose-300',
  info: 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-950/40 dark:text-sky-300',
  neutral: 'bg-slate-100 text-slate-600 ring-slate-500/20 dark:bg-slate-800 dark:text-slate-300',
  brand: 'bg-brand-50 text-brand-700 ring-brand-600/20 dark:bg-brand-950/40 dark:text-brand-300',
}

const dots: Record<BadgeTone, string> = {
  success: 'bg-emerald-500',
  warning: 'bg-amber-500',
  danger: 'bg-rose-500',
  info: 'bg-sky-500',
  neutral: 'bg-slate-400',
  brand: 'bg-brand-500',
}
</script>

<template>
  <span
    class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
    :class="tones[tone]"
  >
    <span v-if="dot" class="h-1.5 w-1.5 rounded-full" :class="dots[tone]" aria-hidden="true" />
    <slot />
  </span>
</template>
