<script setup lang="ts">
import { computed } from 'vue'
import AppIcon from '@/components/AppIcon.vue'

const props = withDefaults(defineProps<{ provider: string; size?: number }>(), { size: 36 })

// Glifos de marca simplificados (24x24, relleno) y su color de fondo.
const brands: Record<string, { bg: string; path?: string }> = {
  facebook: {
    bg: 'bg-[#1877F2] text-white',
    path: 'M14 8h2.5V4.5H14c-2.2 0-4 1.8-4 4V11H8v3.5h2V21h3.5v-6.5H16l.5-3.5h-3V8.8c0-.4.3-.8.8-.8H14z',
  },
  instagram: {
    bg: 'bg-gradient-to-tr from-[#F58529] via-[#DD2A7B] to-[#8134AF] text-white',
    path: 'M12 7.3a4.7 4.7 0 100 9.4 4.7 4.7 0 000-9.4zm0 7.7a3 3 0 110-6 3 3 0 010 6zm4.9-7.9a1.1 1.1 0 11-2.2 0 1.1 1.1 0 012.2 0zM12 4.6c2.4 0 2.7 0 3.6.1 2.4.1 3.6 1.3 3.7 3.7.1.9.1 1.2.1 3.6s0 2.7-.1 3.6c-.1 2.4-1.3 3.6-3.7 3.7-.9.1-1.2.1-3.6.1s-2.7 0-3.6-.1c-2.4-.1-3.6-1.3-3.7-3.7-.1-.9-.1-1.2-.1-3.6s0-2.7.1-3.6C4.8 6 6 4.8 8.4 4.7c.9-.1 1.2-.1 3.6-.1zM12 3c-2.4 0-2.8 0-3.7.1C5 3.2 3.2 5 3.1 8.3 3 9.2 3 9.6 3 12s0 2.8.1 3.7c.1 3.3 1.9 5.1 5.2 5.2.9.1 1.3.1 3.7.1s2.8 0 3.7-.1c3.3-.1 5.1-1.9 5.2-5.2.1-.9.1-1.3.1-3.7s0-2.8-.1-3.7C20.8 5 19 3.2 15.7 3.1 14.8 3 14.4 3 12 3z',
  },
}

const brand = computed(() => brands[props.provider] ?? null)
const glyph = computed(() => Math.round(props.size * 0.55))
</script>

<template>
  <span
    class="inline-grid shrink-0 place-items-center rounded-lg"
    :class="brand?.bg ?? 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-300'"
    :style="{ width: `${size}px`, height: `${size}px` }"
    aria-hidden="true"
  >
    <svg v-if="brand?.path" :width="glyph" :height="glyph" viewBox="0 0 24 24" fill="currentColor">
      <path :d="brand.path" />
    </svg>
    <AppIcon v-else name="social" :size="glyph" />
  </span>
</template>
