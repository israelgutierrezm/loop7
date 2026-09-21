<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage } from '@/utils/errors'
import PageHeader from '@/components/ui/PageHeader.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import BrandPicker from '@/components/BrandPicker.vue'
import StatCard from '@/components/StatCard.vue'
import AppIcon from '@/components/AppIcon.vue'

interface SeriesPoint { date: string; impressions: number; reach: number; engagement: number }
interface Delta { value: number; pct: number | null }
interface Channel { provider: string; reach: number; impressions: number; engagement: number }
interface TopPost {
  id: string | null
  title: string
  provider: string
  destination: string | null
  remote_url: string | null
  impressions: number
  reach: number
  likes: number
  comments: number
  shares: number
  engagement: number
}
interface Overview {
  range: { from: string; to: string }
  kpis: { followers: number; reach: number; impressions: number; engagement: number; posts_published: number }
  deltas: { reach: Delta; impressions: Delta; engagement: Delta }
  series: SeriesPoint[]
  by_channel: Channel[]
  top_posts: TopPost[]
  can_export: boolean
}

const auth = useAuthStore()
const toasts = useToastStore()

const brandId = ref<string | null>(auth.brands[0]?.id ?? null)
const days = ref(30)
const metric = ref<'impressions' | 'reach' | 'engagement'>('impressions')
const data = ref<Overview | null>(null)
const loading = ref(false)
const failed = ref(false)
const syncing = ref(false)

const metricLabels = { impressions: 'Impresiones', reach: 'Alcance', engagement: 'Interacciones' }

function rangeParams(): { from: string; to: string } {
  const to = new Date()
  const from = new Date()
  from.setDate(from.getDate() - (days.value - 1))
  return { from: from.toISOString().slice(0, 10), to: to.toISOString().slice(0, 10) }
}

async function load(): Promise<void> {
  if (!brandId.value) return
  loading.value = true
  failed.value = false
  try {
    const { data: res } = await http.get(`/brands/${brandId.value}/analytics/overview`, { params: rangeParams() })
    data.value = res.data
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

async function sync(): Promise<void> {
  if (!brandId.value) return
  syncing.value = true
  try {
    const { data: res } = await http.post(`/brands/${brandId.value}/analytics/sync`)
    toasts.success(`Actualizado: ${res.data.accounts} cuentas, ${res.data.posts} publicaciones.`)
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    syncing.value = false
  }
}

async function exportCsv(): Promise<void> {
  if (!brandId.value) return
  try {
    const res = await http.get(`/brands/${brandId.value}/analytics/export`, {
      params: rangeParams(),
      responseType: 'blob',
    })
    const url = URL.createObjectURL(res.data as Blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `analitica-${rangeParams().from}-${rangeParams().to}.csv`
    a.click()
    URL.revokeObjectURL(url)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

const hasData = computed(() => (data.value?.series.length ?? 0) > 0 || (data.value?.kpis.followers ?? 0) > 0)

// Puntos del gráfico SVG para la métrica seleccionada.
const chart = computed(() => {
  const series = data.value?.series ?? []
  const w = 600
  const h = 180
  const pad = 8
  if (series.length === 0) return { line: '', area: '', w, h }
  const values = series.map((p) => p[metric.value])
  const max = Math.max(...values, 1)
  const stepX = series.length > 1 ? (w - pad * 2) / (series.length - 1) : 0
  const pts = values.map((v, i) => {
    const x = pad + i * stepX
    const y = h - pad - (v / max) * (h - pad * 2)
    return [x, y] as const
  })
  const line = pts.map((p, i) => `${i === 0 ? 'M' : 'L'}${p[0].toFixed(1)},${p[1].toFixed(1)}`).join(' ')
  const area = `${line} L${pts[pts.length - 1][0].toFixed(1)},${h - pad} L${pts[0][0].toFixed(1)},${h - pad} Z`
  return { line, area, w, h }
})

function fmt(n: number): string {
  return new Intl.NumberFormat('es').format(n)
}
function deltaText(d: Delta): string {
  if (d.pct === null) return '—'
  return `${d.pct > 0 ? '+' : ''}${d.pct}%`
}
function deltaClass(d: Delta): string {
  if (d.pct === null || d.pct === 0) return 'text-slate-400'
  return d.pct > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'
}

const maxChannel = computed(() => Math.max(1, ...(data.value?.by_channel ?? []).map((c) => c.impressions)))

watch([brandId, days], load)
onMounted(load)
</script>

<template>
  <div>
    <PageHeader title="Analítica" description="Rendimiento por canal, publicación y periodo.">
      <template #actions>
        <BrandPicker v-model="brandId" />
      </template>
    </PageHeader>

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <div class="inline-flex rounded-lg border border-slate-200 p-0.5 dark:border-slate-700">
        <button
          v-for="d in [7, 30, 90]"
          :key="d"
          class="rounded-md px-3 py-1.5 text-sm font-medium transition"
          :class="days === d ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'"
          @click="days = d"
        >
          {{ d }} días
        </button>
      </div>
      <div class="flex items-center gap-2">
        <button v-if="data?.can_export" class="btn-secondary text-sm" @click="exportCsv">
          <AppIcon name="analytics" :size="16" /> Exportar CSV
        </button>
        <button class="btn-secondary text-sm" :disabled="syncing || !brandId" @click="sync">
          <AppIcon name="refresh" :size="16" :class="syncing ? 'animate-spin' : ''" /> Actualizar datos
        </button>
      </div>
    </div>

    <EmptyState v-if="!brandId" icon="brands" title="Crea una marca primero" description="La analítica se calcula por marca." />
    <div v-else-if="loading" class="card p-6"><div class="skeleton h-64 w-full" /></div>
    <ErrorState v-else-if="failed" @retry="load" />

    <template v-else-if="data">
      <EmptyState
        v-if="!hasData"
        icon="analytics"
        title="Sin datos todavía"
        description="Conecta una red social y pulsa «Actualizar datos» para traer las métricas."
      >
        <template #action>
          <button class="btn-primary text-sm" :disabled="syncing" @click="sync">Actualizar datos</button>
        </template>
      </EmptyState>

      <template v-else>
        <!-- KPIs -->
        <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
          <StatCard label="Seguidores" :value="fmt(data.kpis.followers)" icon="team" :hint="`${data.kpis.posts_published} publicaciones`" />
          <div class="card p-5">
            <p class="text-sm text-slate-500">Alcance</p>
            <p class="text-xl font-bold text-slate-900 dark:text-white">{{ fmt(data.kpis.reach) }}</p>
            <p class="text-xs font-medium" :class="deltaClass(data.deltas.reach)">{{ deltaText(data.deltas.reach) }} vs periodo anterior</p>
          </div>
          <div class="card p-5">
            <p class="text-sm text-slate-500">Impresiones</p>
            <p class="text-xl font-bold text-slate-900 dark:text-white">{{ fmt(data.kpis.impressions) }}</p>
            <p class="text-xs font-medium" :class="deltaClass(data.deltas.impressions)">{{ deltaText(data.deltas.impressions) }} vs periodo anterior</p>
          </div>
          <div class="card p-5">
            <p class="text-sm text-slate-500">Interacciones</p>
            <p class="text-xl font-bold text-slate-900 dark:text-white">{{ fmt(data.kpis.engagement) }}</p>
            <p class="text-xs font-medium" :class="deltaClass(data.deltas.engagement)">{{ deltaText(data.deltas.engagement) }} vs periodo anterior</p>
          </div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
          <!-- Serie temporal -->
          <div class="card p-6 lg:col-span-2">
            <div class="mb-4 flex items-center justify-between">
              <h3 class="font-semibold text-slate-900 dark:text-white">Evolución</h3>
              <div class="inline-flex rounded-lg border border-slate-200 p-0.5 text-xs dark:border-slate-700">
                <button
                  v-for="(label, key) in metricLabels"
                  :key="key"
                  class="rounded-md px-2.5 py-1 font-medium transition"
                  :class="metric === key ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'text-slate-500'"
                  @click="metric = key as typeof metric"
                >
                  {{ label }}
                </button>
              </div>
            </div>
            <svg :viewBox="`0 0 ${chart.w} ${chart.h}`" class="h-48 w-full" preserveAspectRatio="none">
              <defs>
                <linearGradient id="areaGrad" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0%" stop-color="rgb(99 102 241)" stop-opacity="0.25" />
                  <stop offset="100%" stop-color="rgb(99 102 241)" stop-opacity="0" />
                </linearGradient>
              </defs>
              <path :d="chart.area" fill="url(#areaGrad)" />
              <path :d="chart.line" fill="none" stroke="rgb(99 102 241)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
            </svg>
            <div class="mt-2 flex justify-between text-xs text-slate-400">
              <span>{{ data.range.from }}</span>
              <span>{{ data.range.to }}</span>
            </div>
          </div>

          <!-- Por canal -->
          <div class="card p-6">
            <h3 class="mb-4 font-semibold text-slate-900 dark:text-white">Por canal</h3>
            <div v-if="data.by_channel.length === 0" class="text-sm text-slate-400">Sin datos por canal.</div>
            <div v-for="c in data.by_channel" :key="c.provider" class="mb-3">
              <div class="mb-1 flex items-center justify-between text-sm">
                <span class="font-medium capitalize text-slate-700 dark:text-slate-200">{{ c.provider }}</span>
                <span class="text-slate-500">{{ fmt(c.impressions) }} impr.</span>
              </div>
              <div class="h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                <div class="h-full rounded-full bg-brand-500" :style="{ width: (c.impressions / maxChannel) * 100 + '%' }" />
              </div>
            </div>
          </div>
        </div>

        <!-- Top publicaciones -->
        <div class="card mt-6 overflow-hidden">
          <div class="border-b border-slate-100 px-6 py-4 dark:border-slate-800">
            <h3 class="font-semibold text-slate-900 dark:text-white">Mejores publicaciones</h3>
          </div>
          <div v-if="data.top_posts.length === 0" class="p-6 text-sm text-slate-400">Sin publicaciones con métricas.</div>
          <div v-else class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400 dark:bg-slate-800/50">
                <tr>
                  <th class="px-6 py-3 font-semibold">Publicación</th>
                  <th class="px-3 py-3 font-semibold">Red</th>
                  <th class="px-3 py-3 text-right font-semibold">Impr.</th>
                  <th class="px-3 py-3 text-right font-semibold">Alcance</th>
                  <th class="px-6 py-3 text-right font-semibold">Interac.</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                <tr v-for="p in data.top_posts" :key="p.id ?? p.title" class="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                  <td class="max-w-xs truncate px-6 py-3 font-medium text-slate-800 dark:text-slate-100">{{ p.title }}</td>
                  <td class="px-3 py-3 capitalize text-slate-500">{{ p.provider }}</td>
                  <td class="px-3 py-3 text-right text-slate-600 dark:text-slate-300">{{ fmt(p.impressions) }}</td>
                  <td class="px-3 py-3 text-right text-slate-600 dark:text-slate-300">{{ fmt(p.reach) }}</td>
                  <td class="px-6 py-3 text-right font-semibold text-slate-800 dark:text-slate-100">{{ fmt(p.engagement) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </template>
    </template>
  </div>
</template>
