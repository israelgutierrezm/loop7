<script setup lang="ts">
import type { AutomationRun, RunStatus } from '@/types/automations'
import StatusBadge, { type BadgeTone } from '@/components/ui/StatusBadge.vue'

/** Últimas ejecuciones; cada una se puede ver recorrida sobre el diagrama. */
defineProps<{ runs: AutomationRun[]; loading: boolean; activeId: string | null }>()
const emit = defineEmits<{ show: [run: AutomationRun | null] }>()

const tones: Record<RunStatus, BadgeTone> = {
  success: 'success', failed: 'danger', skipped: 'neutral', waiting: 'warning', running: 'info', cancelled: 'neutral',
}
const labels: Record<RunStatus, string> = {
  success: 'Correcta', failed: 'Fallida', skipped: 'Omitida', waiting: 'En espera', running: 'En curso', cancelled: 'Cancelada',
}

function fmt(value: string | null): string {
  return value ? new Date(value).toLocaleString('es', { dateStyle: 'short', timeStyle: 'short' }) : '—'
}
</script>

<template>
  <section aria-labelledby="runs-title">
    <h2 id="runs-title" class="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Últimas ejecuciones</h2>
    <div v-if="loading" class="skeleton h-16 w-full" />
    <p v-else-if="runs.length === 0" class="text-xs text-slate-500">Todavía no se ha ejecutado.</p>
    <ul v-else class="divide-y divide-slate-100 rounded-lg border border-slate-200 text-xs dark:divide-slate-800 dark:border-slate-700">
      <li v-for="r in runs" :key="r.id">
        <button
          type="button"
          class="flex w-full flex-col gap-1 px-3 py-2 text-left transition hover:bg-slate-50 focus-visible:bg-slate-50 focus-visible:outline-none dark:hover:bg-slate-800/60"
          :class="activeId === r.id ? 'bg-brand-50 dark:bg-brand-950/30' : ''"
          :aria-pressed="activeId === r.id"
          @click="emit('show', activeId === r.id ? null : r)"
        >
          <span class="flex flex-wrap items-center gap-2">
            <StatusBadge :tone="tones[r.status]" dot>{{ labels[r.status] }}</StatusBadge>
            <span class="text-slate-400">{{ fmt(r.created_at) }}</span>
          </span>
          <span v-if="r.status === 'waiting' && r.resume_at" class="text-amber-700 dark:text-amber-400">Sigue el {{ fmt(r.resume_at) }}</span>
          <span v-if="r.message" class="truncate text-slate-600 dark:text-slate-300" :title="r.message">{{ r.message }}</span>
        </button>
      </li>
    </ul>
    <p v-if="runs.length" class="mt-1.5 text-[11px] text-slate-400">Elige una para verla recorrida en el diagrama.</p>
  </section>
</template>
