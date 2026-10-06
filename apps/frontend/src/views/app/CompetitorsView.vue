<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { apiErrorMessage } from '@/utils/errors'
import type { Benchmark, Competitor, CompetitorIndex } from '@/types/competitors'
import PageHeader from '@/components/ui/PageHeader.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import BrandPicker from '@/components/BrandPicker.vue'
import AppIcon from '@/components/AppIcon.vue'
import BenchmarkTable from '@/components/competitors/BenchmarkTable.vue'
import CompetitorDialog from '@/components/competitors/CompetitorDialog.vue'
import CompetitorList from '@/components/competitors/CompetitorList.vue'
import GrowthChart from '@/components/competitors/GrowthChart.vue'
import TopPosts from '@/components/competitors/TopPosts.vue'

/**
 * Competencia (docs/05): tu marca frente a las cuentas públicas de tus
 * competidores en las redes cuya API oficial lo permite.
 */
const auth = useAuthStore()

const MAX_ACCOUNTS = 6
const brandId = ref<string | null>(auth.brands[0]?.id ?? null)
const days = ref(30)
const provider = ref<string>('')
const index = ref<CompetitorIndex | null>(null)
const benchmark = ref<Benchmark | null>(null)
const loading = ref(true)
const failed = ref(false)
const dialog = ref(false)
const target = ref<Competitor | null>(null)

const canManage = computed(() => auth.can('analytics.competitors'))
const usage = computed(() => index.value?.usage ?? { used: 0, limit: 0 })
const included = computed(() => usage.value.limit !== 0 || (index.value?.competitors.length ?? 0) > 0)
const full = computed(() => usage.value.limit !== -1 && usage.value.used >= usage.value.limit)
const unavailable = computed(() => (index.value?.sources ?? []).filter((s) => !s.available))
const hasCompetitorRows = computed(() => (benchmark.value?.rows ?? []).some((r) => r.kind === 'competitor'))

async function load(): Promise<void> {
  if (!brandId.value) {
    loading.value = false
    return
  }
  loading.value = true
  failed.value = false
  try {
    const [list, bench] = await Promise.all([
      http.get(`/brands/${brandId.value}/competitors`),
      http.get(`/brands/${brandId.value}/competitors/benchmark`, { params: { days: days.value, provider: provider.value || undefined } }),
    ])
    index.value = list.data.data
    benchmark.value = bench.data.data
  } catch (e) {
    failed.value = true
    console.warn(apiErrorMessage(e))
  } finally {
    loading.value = false
  }
}

function openAdd(competitor: Competitor | null = null): void {
  target.value = competitor
  dialog.value = true
}

watch([brandId, days, provider], load)
onMounted(load)
</script>

<template>
  <div class="space-y-4">
    <PageHeader title="Competencia" description="Compara tus redes con las de tu competencia: seguidores, crecimiento e interacción con sus datos públicos.">
      <template #actions>
        <BrandPicker v-model="brandId" />
        <button v-if="canManage && included" type="button" class="btn-primary text-sm" :disabled="full || loading" :title="full ? 'Llegaste al límite de tu plan' : ''" @click="openAdd()">
          <AppIcon name="plus" :size="16" /> Añadir competidor
        </button>
      </template>
    </PageHeader>

    <EmptyState v-if="!brandId" icon="brands" title="Crea una marca primero" description="La competencia se compara con las cuentas de cada marca." />
    <div v-else-if="loading && !index" class="card p-6"><div class="skeleton h-48 w-full" /></div>
    <ErrorState v-else-if="failed" @retry="load" />

    <template v-else-if="index && benchmark">
      <!-- Plan sin competencia -->
      <EmptyState
        v-if="!included"
        icon="analytics"
        title="El análisis de competidores no está en tu plan"
        description="Sigue las cuentas públicas de tu competencia en Instagram, Facebook y Threads y compárate con ellas."
      >
        <template v-if="auth.can('billing.view')" #action>
          <RouterLink to="/app/billing" class="btn-primary text-sm">Ver planes</RouterLink>
        </template>
      </EmptyState>

      <template v-else>
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="flex flex-wrap items-center gap-2" role="group" aria-label="Periodo">
            <button
              v-for="d in [7, 30, 90]"
              :key="d"
              type="button"
              class="rounded-lg px-3 py-1.5 text-sm font-medium transition"
              :class="days === d ? 'bg-brand-600 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-700'"
              :aria-pressed="days === d"
              @click="days = d"
            >
              {{ d }} días
            </button>
            <label class="sr-only" for="competitors-provider">Red</label>
            <select id="competitors-provider" v-model="provider" class="input w-auto py-1.5 text-sm">
              <option value="">Todas las redes</option>
              <option v-for="s in index.sources" :key="s.key" :value="s.key">{{ s.label }}</option>
            </select>
          </div>
          <p class="text-xs text-slate-500" role="status">
            Sigues {{ usage.used }} de {{ usage.limit === -1 ? '∞' : usage.limit }} cuentas de la competencia.
            <RouterLink v-if="full && auth.can('billing.view')" to="/app/billing" class="font-medium text-brand-600 hover:underline">Ampliar</RouterLink>
          </p>
        </div>

        <EmptyState
          v-if="index.competitors.length === 0"
          icon="analytics"
          title="Aún no sigues a ningún competidor"
          description="Añade a tus competidores con sus cuentas de Instagram, Facebook o Threads: cada día tomamos una foto de sus métricas públicas."
        >
          <template v-if="canManage" #action>
            <button type="button" class="btn-primary text-sm" @click="openAdd()">Añadir competidor</button>
          </template>
        </EmptyState>

        <template v-else>
          <section class="card" aria-labelledby="benchmark-title">
            <div class="flex flex-wrap items-center justify-between gap-2 px-4 pt-4">
              <h2 id="benchmark-title" class="font-semibold text-slate-900 dark:text-white">Tú frente a tu competencia</h2>
              <p class="text-xs text-slate-500">Últimos {{ benchmark.period.days }} días</p>
            </div>
            <BenchmarkTable :rows="benchmark.rows" :days="benchmark.period.days" class="mt-2" />
            <p v-if="!hasCompetitorRows" class="px-4 pb-4 text-sm text-slate-500">Ninguna cuenta de la competencia en esta red.</p>
            <p class="px-4 pb-3 pt-2 text-[11px] text-slate-400">
              Interacción = «me gusta» + comentarios por publicación. Tus cuentas salen de tu analítica; las de la competencia, de sus datos públicos.
            </p>
          </section>

          <div class="grid gap-4 xl:grid-cols-2">
            <section class="card p-4" aria-labelledby="growth-title">
              <h2 id="growth-title" class="mb-3 font-semibold text-slate-900 dark:text-white">Crecimiento de seguidores</h2>
              <GrowthChart :dates="benchmark.series.dates" :lines="benchmark.series.lines" />
            </section>
            <section class="card p-4" aria-labelledby="top-title">
              <h2 id="top-title" class="font-semibold text-slate-900 dark:text-white">Lo que mejor les funciona</h2>
              <p class="text-xs text-slate-500">Publicaciones de la competencia con más interacción en el periodo.</p>
              <TopPosts :posts="benchmark.top_posts" class="mt-1" />
            </section>
          </div>

          <section class="card p-4" aria-labelledby="list-title">
            <h2 id="list-title" class="font-semibold text-slate-900 dark:text-white">Competidores</h2>
            <CompetitorList
              :brand-id="brandId"
              :competitors="index.competitors"
              :can-manage="canManage"
              :max-accounts="MAX_ACCOUNTS"
              @changed="load"
              @add-account="openAdd"
            />
          </section>
        </template>

        <section class="rounded-xl border border-dashed border-slate-200 p-4 text-xs text-slate-500 dark:border-slate-700">
          <p class="font-medium text-slate-600 dark:text-slate-300">De dónde salen los datos</p>
          <p class="mt-1">
            Sólo datos públicos y por las APIs oficiales: Instagram (cuentas profesionales: seguidores y publicaciones), Facebook (páginas: seguidores)
            y Threads (perfiles públicos: seguidores e interacción de 7 días). YouTube, TikTok, LinkedIn y X no permiten este análisis con sus APIs.
          </p>
          <ul v-if="unavailable.length" class="mt-2 space-y-0.5">
            <li v-for="s in unavailable" :key="s.key"><strong>{{ s.label }}:</strong> {{ s.reason }}</li>
          </ul>
        </section>
      </template>
    </template>

    <CompetitorDialog
      v-if="brandId && index"
      :open="dialog"
      :brand-id="brandId"
      :sources="index.sources"
      :competitor="target"
      :max="MAX_ACCOUNTS"
      @close="dialog = false"
      @saved="load"
    />
  </div>
</template>
