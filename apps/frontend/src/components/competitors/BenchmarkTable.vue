<script setup lang="ts">
import { computed, ref } from 'vue'
import type { BenchmarkRow } from '@/types/competitors'
import { formatCompact, formatNumber, formatPercent, formatSigned } from '@/utils/numbers'
import ProviderIcon from '@/components/social/ProviderIcon.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import AppIcon from '@/components/AppIcon.vue'

/** Tu marca frente a la competencia, ordenable por cualquier columna. */
const props = defineProps<{ rows: BenchmarkRow[]; days: number }>()

type SortKey = 'followers' | 'followers_change_pct' | 'posts' | 'avg_engagement' | 'engagement_rate'

const columns: { key: SortKey; label: string; hint: string }[] = [
  { key: 'followers', label: 'Seguidores', hint: 'Última foto del periodo.' },
  { key: 'followers_change_pct', label: 'Crecimiento', hint: 'Variación de seguidores en el periodo.' },
  { key: 'posts', label: 'Publicaciones', hint: 'Publicaciones en el periodo (Facebook y Threads no las dan).' },
  { key: 'avg_engagement', label: 'Interacción media', hint: '«Me gusta» + comentarios por publicación.' },
  { key: 'engagement_rate', label: 'Tasa de interacción', hint: 'Interacción media entre seguidores.' },
]

const sortKey = ref<SortKey>('followers')
const sortDesc = ref(true)

const sorted = computed(() =>
  [...props.rows].sort((a, b) => {
    const x = a[sortKey.value]
    const y = b[sortKey.value]
    // Sin dato, siempre al final.
    if (x === null && y === null) return 0
    if (x === null) return 1
    if (y === null) return -1
    return sortDesc.value ? y - x : x - y
  }),
)

function sortBy(key: SortKey): void {
  if (sortKey.value === key) sortDesc.value = !sortDesc.value
  else {
    sortKey.value = key
    sortDesc.value = true
  }
}

function changeTone(value: number | null): string {
  if (value === null || value === 0) return 'text-slate-500'
  return value > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'
}
</script>

<template>
  <div class="overflow-x-auto">
    <table class="w-full min-w-[720px] text-sm">
      <caption class="sr-only">Comparación de tu marca con la competencia en los últimos {{ days }} días</caption>
      <thead>
        <tr class="border-b border-slate-100 text-left text-xs text-slate-500 dark:border-slate-800">
          <th scope="col" class="py-2 pl-4 pr-3 font-semibold">Cuenta</th>
          <th v-for="c in columns" :key="c.key" scope="col" class="px-3 py-2 text-right font-semibold" :aria-sort="sortKey === c.key ? (sortDesc ? 'descending' : 'ascending') : 'none'">
            <button type="button" class="inline-flex items-center gap-1 hover:text-slate-800 dark:hover:text-slate-200" :title="c.hint" @click="sortBy(c.key)">
              {{ c.label }}
              <AppIcon v-if="sortKey === c.key" :name="sortDesc ? 'arrow-down' : 'arrow-up'" :size="12" />
            </button>
          </th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
        <tr v-for="row in sorted" :key="row.key" :class="row.kind === 'own' ? 'bg-brand-50/50 dark:bg-brand-950/20' : ''">
          <th scope="row" class="py-2.5 pl-4 pr-3 text-left font-normal">
            <span class="flex items-center gap-2.5">
              <ProviderIcon :provider="row.provider" :size="28" />
              <span class="min-w-0">
                <span class="flex items-center gap-1.5">
                  <span class="truncate font-medium text-slate-800 dark:text-slate-100">{{ row.name }}</span>
                  <StatusBadge v-if="row.kind === 'own'" tone="brand">Tú</StatusBadge>
                  <StatusBadge v-if="row.status === 'error'" tone="danger" :title="row.last_error ?? ''">Sin actualizar</StatusBadge>
                </span>
                <a
                  v-if="row.profile_url"
                  :href="row.profile_url"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="block truncate text-xs text-slate-500 hover:text-brand-600 hover:underline"
                >{{ row.account }}</a>
                <span v-else class="block truncate text-xs text-slate-500">{{ row.account }}</span>
              </span>
            </span>
          </th>
          <td class="px-3 py-2.5 text-right tabular-nums text-slate-800 dark:text-slate-100">{{ formatCompact(row.followers) }}</td>
          <td class="px-3 py-2.5 text-right tabular-nums" :class="changeTone(row.followers_change)">
            <span class="block">{{ formatPercent(row.followers_change_pct) }}</span>
            <span class="block text-[11px] opacity-80">{{ formatSigned(row.followers_change) }}</span>
          </td>
          <td class="px-3 py-2.5 text-right tabular-nums text-slate-700 dark:text-slate-200">{{ formatNumber(row.posts) }}</td>
          <td class="px-3 py-2.5 text-right tabular-nums text-slate-700 dark:text-slate-200">
            {{ formatNumber(row.avg_engagement) }}
            <span v-if="row.weekly" class="block text-[11px] text-slate-500" :title="'Totales de los últimos 7 días que da Threads'">
              7 días: {{ formatCompact((row.weekly.likes ?? 0) + (row.weekly.reposts ?? 0) + (row.weekly.quotes ?? 0)) }} interacciones
            </span>
          </td>
          <td class="px-3 py-2.5 pr-4 text-right tabular-nums text-slate-700 dark:text-slate-200">{{ formatPercent(row.engagement_rate, false) }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
