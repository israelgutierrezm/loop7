<script setup lang="ts">
import { ref, watch } from 'vue'

/** Logo de la marca o, si no tiene (o no carga), su inicial con el color de la marca. */
const props = withDefaults(
  defineProps<{ name: string; logoUrl?: string | null; color?: string | null; size?: number }>(),
  { logoUrl: null, color: null, size: 40 },
)

const failed = ref(false)
watch(() => props.logoUrl, () => (failed.value = false))
</script>

<template>
  <img
    v-if="logoUrl && !failed"
    :src="logoUrl"
    :alt="`Logo de ${name}`"
    class="shrink-0 rounded-xl bg-white object-contain ring-1 ring-slate-200 dark:ring-slate-700"
    :style="{ width: `${size}px`, height: `${size}px` }"
    @error="failed = true"
  />
  <span
    v-else
    class="grid shrink-0 place-items-center rounded-xl font-bold text-white"
    :style="{ width: `${size}px`, height: `${size}px`, backgroundColor: color ?? '#6366f1', fontSize: `${Math.round(size * 0.38)}px` }"
    aria-hidden="true"
  >
    {{ name.charAt(0).toUpperCase() }}
  </span>
</template>
