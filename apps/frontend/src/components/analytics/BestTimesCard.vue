<script setup lang="ts">
import { ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { slotLabel, useBestTimes } from '@/composables/useBestTimes'
import { providerName } from '@/utils/providers'

/**
 * Mejores horarios para publicar de una marca (docs/05): mapa de calor por día y
 * hora en la zona de la marca, franjas recomendadas y avance cuando aún faltan
 * datos. Sin analítica avanzada en el plan, invita a mejorarlo.
 */
const props = defineProps<{
  brandId: string | null
  /** Redes con datos de la marca (para filtrar). */
  providers: string[]
}>()

const auth = useAuthStore()
const { data, loading, failed, available, load } = useBestTimes()
const provider = ref('')

const SHORT_DAYS = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom']
const LEGEND: [string, string][] = [
  ['bg-rose-100 dark:bg-rose-950/60', 'Peor que lo habitual'],
  ['bg-slate-200 dark:bg-slate-700', 'Lo habitual'],
  ['bg-emerald-100 dark:bg-emerald-900/50', 'Mejor'],
  ['bg-emerald-300 dark:bg-emerald-700', 'Bastante mejor'],
  ['bg-emerald-500 dark:bg-emerald-500', 'Mucho mejor'],
  ['bg-slate-100 dark:bg-slate-800/60', 'Sin datos'],
]

function reload(): void {
  load(props.brandId, { providers: provider.value ? [provider.value] : [] })
}

function capitalize(text: string): string {
  return text.charAt(0).toUpperCase() + text.slice(1)
}

function cellClass(score: number | null): string {
  if (score === null) return 'bg-slate-100 dark:bg-slate-800/60'
  if (score < 0.9) return 'bg-rose-100 dark:bg-rose-950/60'
  if (score < 1.05) return 'bg-slate-200 dark:bg-slate-700'
  if (score < 1.25) return 'bg-emerald-100 dark:bg-emerald-900/50'
  if (score < 1.5) return 'bg-emerald-300 dark:bg-emerald-700'
  return 'bg-emerald-500 dark:bg-emerald-500'
}

function cellTitle(dayIndex: number, hour: number): string {
  const score = data.value?.heatmap[dayIndex]?.[hour] ?? null
  const posts = data.value?.counts[dayIndex]?.[hour] ?? 0
  const when = capitalize(slotLabel(dayIndex + 1, hour))
  if (score === null) return `${when}: sin datos`
  const pct = Math.round((score - 1) * 100)
  const vs = pct === 0 ? 'como lo habitual' : `${pct > 0 ? '+' : ''}${pct}% sobre lo habitual`
  return `${when}: ${vs} · ${posts} ${posts === 1 ? 'publicación' : 'publicaciones'}`
}

function isTop(weekday: number, hour: number): boolean {
  return data.value?.top.some((t) => t.weekday === weekday && t.hour === hour) ?? false
}

watch(() => [props.brandId, provider.value, available.value], reload, { immediate: true })
// Si la red elegida deja de tener datos (p. ej. al cambiar de marca), vuelve a «todas».
watch(() => props.providers, (list) => {
  if (provider.value && !list.includes(provider.value)) provider.value = ''
})
</script>

<template>
  <section class="card p-6" aria-labelledby="best-times-title">
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
      <div>
        <h3 id="best-times-title" class="font-semibold text-slate-900 dark:text-white">Mejores horarios para publicar</h3>
        <p class="mt-0.5 text-xs text-slate-500">Cada publicación se compara con lo habitual de su cuenta, según la hora a la que salió.</p>
      </div>
      <div v-if="available && providers.length > 1">
        <label for="best-provider" class="sr-only">Red social</label>
        <select id="best-provider" v-model="provider" class="input w-auto py-1.5 text-sm">
          <option value="">Todas las redes</option>
          <option v-for="p in providers" :key="p" :value="p">{{ providerName(p) }}</option>
        </select>
      </div>
    </div>

    <!-- Sin analítica avanzada en el plan -->
    <div v-if="!available" class="rounded-lg border border-dashed border-slate-300 p-4 text-sm text-slate-600 dark:border-slate-700 dark:text-slate-300">
      Descubre a qué horas le funciona mejor publicar a esta marca, con sugerencias en el calendario y al programar.
      Disponible en los planes con analítica avanzada.
      <RouterLink v-if="auth.can('billing.view')" to="/app/billing" class="font-medium text-brand-600 hover:underline dark:text-brand-400">Ver planes</RouterLink>
    </div>

    <div v-else-if="loading && !data" class="skeleton h-48 w-full" />

    <p v-else-if="failed" class="text-sm text-slate-500">
      No se pudieron calcular los mejores horarios.
      <button type="button" class="font-medium text-brand-600 hover:underline dark:text-brand-400" @click="reload">Reintentar</button>
    </p>

    <template v-else-if="data">
      <!-- Aún sin datos suficientes -->
      <div v-if="!data.sufficient">
        <p class="text-sm text-slate-600 dark:text-slate-300">
          Aún no hay datos suficientes: se necesitan al menos {{ data.min_posts }} publicaciones medidas de los últimos
          {{ data.window_days }} días (con 48 h de vida para que sus métricas se asienten). Llevas {{ data.sample }}.
        </p>
        <div
          class="mt-3 h-2 w-full max-w-sm overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800"
          role="progressbar"
          aria-label="Publicaciones medidas"
          :aria-valuenow="data.sample"
          aria-valuemin="0"
          :aria-valuemax="data.min_posts"
        >
          <div class="h-full rounded-full bg-brand-500" :style="{ width: `${Math.min(100, (data.sample / data.min_posts) * 100)}%` }" />
        </div>
        <p class="mt-2 text-xs text-slate-400">Consejo: publica a horas distintas para descubrir cuáles funcionan mejor con tu audiencia.</p>
      </div>

      <div v-else class="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_15rem]">
        <div class="min-w-0">
          <div class="overflow-x-auto">
            <!-- table-fixed: las horas sin rótulo no se colapsan (mismo ancho para las 24). -->
            <table class="w-full min-w-[38rem] table-fixed border-separate border-spacing-0.5 text-[10px]">
              <caption class="sr-only">Rendimiento de cada día y hora frente a lo habitual</caption>
              <thead>
                <tr>
                  <th scope="col" class="w-9"><span class="sr-only">Día</span></th>
                  <th v-for="h in 24" :key="h" scope="col" class="font-normal text-slate-400">
                    {{ (h - 1) % 3 === 0 ? String(h - 1).padStart(2, '0') : '' }}
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, d) in data.heatmap" :key="d">
                  <th scope="row" class="pr-1 text-left font-medium text-slate-500">{{ SHORT_DAYS[d] }}</th>
                  <td
                    v-for="(score, h) in row"
                    :key="h"
                    class="h-6 rounded-sm"
                    :class="[cellClass(score), isTop(d + 1, h) ? 'ring-2 ring-inset ring-brand-600 dark:ring-brand-400' : '']"
                    :title="cellTitle(d, h)"
                  >
                    <span class="sr-only">{{ cellTitle(d, h) }}</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <ul class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-slate-500" aria-label="Leyenda">
            <li v-for="[cls, label] in LEGEND" :key="label" class="flex items-center gap-1.5">
              <span class="h-3 w-3 rounded-sm" :class="cls" aria-hidden="true" />{{ label }}
            </li>
            <li class="flex items-center gap-1.5">
              <span class="h-3 w-3 rounded-sm ring-2 ring-inset ring-brand-600 dark:ring-brand-400" aria-hidden="true" />Recomendado
            </li>
          </ul>
        </div>

        <div>
          <h4 id="best-top-title" class="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Recomendados</h4>
          <ol v-if="data.top.length" class="space-y-2" aria-labelledby="best-top-title">
            <li v-for="(t, i) in data.top" :key="`${t.weekday}-${t.hour}`" class="flex items-center justify-between gap-2 text-sm">
              <span class="text-slate-700 dark:text-slate-200"><span class="mr-1 text-slate-400">{{ i + 1 }}.</span>{{ capitalize(slotLabel(t.weekday, t.hour)) }}</span>
              <span class="font-semibold text-emerald-600 dark:text-emerald-400" :title="`${t.posts} ${t.posts === 1 ? 'publicación' : 'publicaciones'} en esta franja`">+{{ t.lift }}%</span>
            </li>
          </ol>
          <p v-else class="text-sm text-slate-500">Ningún horario destaca todavía sobre lo habitual.</p>
        </div>
      </div>

      <p class="mt-4 text-xs text-slate-400">
        Según {{ data.sample }} {{ data.sample === 1 ? 'publicación medida' : 'publicaciones medidas' }} de los últimos {{ data.window_days }} días.
        Horas en la zona de la marca ({{ data.timezone }}){{ data.timezone === 'UTC' ? ': revisa que sea la de su audiencia en la ficha de la marca' : '' }}.
        <RouterLink v-if="brandId && auth.can('brands.update')" :to="`/app/brands/${brandId}`" class="text-brand-600 hover:underline dark:text-brand-400">Cambiar zona</RouterLink>
      </p>
    </template>
  </section>
</template>
