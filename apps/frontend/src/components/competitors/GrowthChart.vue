<script setup lang="ts">
import { computed } from 'vue'
import type { BenchmarkLine } from '@/types/competitors'
import { formatPercent } from '@/utils/numbers'

/**
 * Crecimiento de seguidores en % desde el primer día con dato de cada cuenta:
 * así se comparan cuentas de tamaños muy distintos.
 */
const props = defineProps<{ dates: string[]; lines: BenchmarkLine[] }>()

const COLORS = ['#6366f1', '#f97316', '#10b981', '#e11d48', '#0ea5e9', '#a855f7', '#eab308', '#64748b']
const W = 640
const H = 220
const PAD = { top: 12, right: 12, bottom: 24, left: 44 }

const series = computed(() =>
  props.lines
    .map((line, i) => {
      const base = line.values.find((v) => v !== null && v > 0) ?? null
      const growth = line.values.map((v) => (v === null || base === null ? null : ((v - base) / base) * 100))
      return { ...line, color: COLORS[i % COLORS.length], growth, last: [...growth].reverse().find((g) => g !== null) ?? null }
    })
    .filter((s) => s.growth.filter((g) => g !== null).length >= 2)
    .slice(0, COLORS.length),
)

const bounds = computed(() => {
  const values = series.value.flatMap((s) => s.growth.filter((g): g is number => g !== null))
  const max = Math.max(1, ...values)
  const min = Math.min(0, ...values)
  return { min, max }
})

function x(i: number): number {
  const n = Math.max(1, props.dates.length - 1)
  return PAD.left + (i / n) * (W - PAD.left - PAD.right)
}

function y(value: number): number {
  const { min, max } = bounds.value
  return PAD.top + (1 - (value - min) / (max - min || 1)) * (H - PAD.top - PAD.bottom)
}

// Une los días con dato (saltando huecos).
function path(growth: (number | null)[]): string {
  let d = ''
  growth.forEach((g, i) => {
    if (g === null) return
    d += `${d === '' ? 'M' : 'L'}${x(i).toFixed(1)},${y(g).toFixed(1)}`
  })
  return d
}

const ticks = computed(() => {
  const { min, max } = bounds.value
  return [max, (max + min) / 2, min].map((v) => ({ value: v, y: y(v) }))
})

function shortDate(date: string): string {
  return new Date(`${date}T12:00:00`).toLocaleDateString('es', { day: 'numeric', month: 'short' })
}
</script>

<template>
  <div>
    <p v-if="series.length === 0" class="py-10 text-center text-sm text-slate-500">
      Aún no hay suficientes días con datos. La foto de cada cuenta se toma a diario.
    </p>
    <template v-else>
      <svg :viewBox="`0 0 ${W} ${H}`" class="h-56 w-full" role="img" :aria-label="`Crecimiento de seguidores de ${series.length} cuentas`">
        <g class="text-slate-400">
          <line v-for="t in ticks" :key="t.value" :x1="PAD.left" :x2="W - PAD.right" :y1="t.y" :y2="t.y" stroke="currentColor" stroke-opacity="0.25" stroke-dasharray="3 3" />
          <text v-for="t in ticks" :key="`l${t.value}`" :x="PAD.left - 6" :y="t.y + 3" text-anchor="end" font-size="10" fill="currentColor">{{ formatPercent(Math.round(t.value * 10) / 10) }}</text>
          <text :x="PAD.left" :y="H - 6" font-size="10" fill="currentColor">{{ shortDate(dates[0]) }}</text>
          <text :x="W - PAD.right" :y="H - 6" text-anchor="end" font-size="10" fill="currentColor">{{ shortDate(dates[dates.length - 1]) }}</text>
        </g>
        <path
          v-for="s in series"
          :key="s.key"
          :d="path(s.growth)"
          fill="none"
          :stroke="s.color"
          :stroke-width="s.kind === 'own' ? 3 : 2"
          :stroke-dasharray="s.kind === 'own' ? undefined : '0'"
          stroke-linejoin="round"
          stroke-linecap="round"
        />
      </svg>
      <ul class="mt-3 flex flex-wrap gap-x-4 gap-y-1.5 text-xs">
        <li v-for="s in series" :key="s.key" class="flex items-center gap-1.5 text-slate-600 dark:text-slate-300">
          <span class="h-2.5 w-2.5 rounded-full" :style="{ background: s.color }" aria-hidden="true" />
          <span :class="s.kind === 'own' ? 'font-semibold' : ''">{{ s.label }}</span>
          <span class="tabular-nums text-slate-400">{{ formatPercent(s.last === null ? null : Math.round(s.last * 10) / 10) }}</span>
        </li>
      </ul>
    </template>
  </div>
</template>
