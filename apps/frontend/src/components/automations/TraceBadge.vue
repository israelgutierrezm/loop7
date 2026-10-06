<script setup lang="ts">
import { computed } from 'vue'
import type { TraceEntry } from '@/types/automations'
import StatusBadge, { type BadgeTone } from '@/components/ui/StatusBadge.vue'

/** Lo que pasó (o pasaría, al probar) en un paso, sobre su tarjeta. */
const props = defineProps<{ entry: TraceEntry }>()

const tones: Record<TraceEntry['status'], BadgeTone> = {
  success: 'success',
  failed: 'danger',
  skipped: 'neutral',
  waiting: 'warning',
  running: 'info',
  cancelled: 'neutral',
}

const text = computed(() => {
  if (props.entry.type === 'branch') return props.entry.path === 'yes' ? 'Siguió por «Sí»' : 'Siguió por «No»'
  return props.entry.message
})
</script>

<template>
  <span class="flex min-w-0 flex-col gap-1">
    <StatusBadge :tone="tones[entry.status]" dot class="max-w-full">
      <span class="truncate">{{ text }}</span>
    </StatusBadge>
    <span v-for="p in entry.preview ?? []" :key="p.label" class="block truncate text-[11px] text-slate-500" :title="p.value">
      <span class="font-medium text-slate-600 dark:text-slate-300">{{ p.label }}:</span> {{ p.value }}
    </span>
  </span>
</template>
