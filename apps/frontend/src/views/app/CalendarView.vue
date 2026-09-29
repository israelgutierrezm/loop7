<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage } from '@/utils/errors'
import PageHeader from '@/components/ui/PageHeader.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import BrandPicker from '@/components/BrandPicker.vue'
import { providerName } from '@/utils/providers'
import { useBestTimes } from '@/composables/useBestTimes'
import AppIcon from '@/components/AppIcon.vue'
import ProviderIcon from '@/components/social/ProviderIcon.vue'
import CalendarTimeGrid, { type CalendarHighlight, type CalendarItem } from '@/components/calendar/CalendarTimeGrid.vue'

interface ReadyItem { id: string; title: string }
type View = 'month' | 'week' | 'day' | 'list'

const STATUS_STYLES: Record<string, string> = {
  scheduled: 'border-brand-200 bg-brand-50 text-brand-800 dark:border-brand-900 dark:bg-brand-950/40 dark:text-brand-200',
  publishing: 'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-200',
  published: 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200',
  partial: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200',
  failed: 'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200',
  unpublished: 'border-slate-200 bg-slate-100 text-slate-500 line-through dark:border-slate-700 dark:bg-slate-800 dark:text-slate-400',
}
const STATUS_OPTIONS: [string, string][] = [
  ['scheduled', 'Programado'],
  ['publishing', 'Publicando'],
  ['published', 'Publicado'],
  ['partial', 'Parcial'],
  ['failed', 'Fallido'],
  ['unpublished', 'Retirado'],
]
const WEEKDAYS = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom']
// Vista en la URL (?vista=semana&fecha=2026-09-28): enlaces y «Atrás» la conservan.
const VIEW_PARAMS: Record<View, string> = { month: 'mes', week: 'semana', day: 'dia', list: 'lista' }
const VIEWS: [View, string][] = [['month', 'Mes'], ['week', 'Semana'], ['day', 'Día'], ['list', 'Lista']]
const PREV: Record<View, string> = { month: 'Mes anterior', week: 'Semana anterior', day: 'Día anterior', list: 'Mes anterior' }
const NEXT: Record<View, string> = { month: 'Mes siguiente', week: 'Semana siguiente', day: 'Día siguiente', list: 'Mes siguiente' }

const auth = useAuthStore()
const toasts = useToastStore()
const route = useRoute()
const router = useRouter()
const canSchedule = auth.can('content.schedule')

function dayKey(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function parseDay(value: unknown): Date | null {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return null
  const [y, m, d] = value.split('-').map(Number)
  const date = new Date(y, m - 1, d)
  return Number.isNaN(date.getTime()) ? null : date
}

function startOfDay(d: Date): Date {
  return new Date(d.getFullYear(), d.getMonth(), d.getDate())
}

function viewFromParam(value: unknown): View {
  return (Object.entries(VIEW_PARAMS).find(([, param]) => param === value)?.[0] ?? 'month') as View
}

const brandId = ref<string | null>(auth.brands[0]?.id ?? null)
const view = ref<View>(viewFromParam(route.query.vista))
/** Día de referencia: su mes, su semana (lunes a domingo) o él mismo, según la vista. */
const anchor = ref<Date>(parseDay(route.query.fecha) ?? startOfDay(new Date()))
const items = ref<CalendarItem[]>([])
const ready = ref<ReadyItem[]>([])
const loading = ref(false)
const failed = ref(false)
const providerFilter = ref('')
const statusFilter = ref('')
const dragging = ref<{ id: string; kind: 'scheduled' | 'ready' } | null>(null)
const dropTarget = ref<string | null>(null)

const monthStart = computed(() => new Date(anchor.value.getFullYear(), anchor.value.getMonth(), 1))

/** Semanas visibles (de lunes a domingo) que cubren el mes. */
const monthDays = computed(() => {
  const first = monthStart.value
  const start = new Date(first)
  start.setDate(first.getDate() - ((first.getDay() + 6) % 7))
  const last = new Date(first.getFullYear(), first.getMonth() + 1, 0)
  const end = new Date(last)
  end.setDate(last.getDate() + (6 - ((last.getDay() + 6) % 7)))
  const list: Date[] = []
  for (const d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) list.push(new Date(d))
  return list
})

const weekDays = computed(() => {
  const monday = new Date(anchor.value)
  monday.setDate(monday.getDate() - ((monday.getDay() + 6) % 7))
  return Array.from({ length: 7 }, (_, i) => new Date(monday.getFullYear(), monday.getMonth(), monday.getDate() + i))
})

/** Días que se consultan según la vista. */
const range = computed<Date[]>(() => {
  if (view.value === 'week') return weekDays.value
  if (view.value === 'day') return [anchor.value]
  return monthDays.value
})

/** Sólo la primera letra en mayúscula («miércoles, 30 de septiembre» → «Miércoles, 30 de septiembre»). */
function capitalize(text: string): string {
  return text.charAt(0).toUpperCase() + text.slice(1)
}

const periodLabel = computed(() => capitalize((() => {
  if (view.value === 'week') {
    const [first, last] = [weekDays.value[0], weekDays.value[6]]
    const sameMonth = first.getMonth() === last.getMonth()
    const from = first.toLocaleDateString('es', sameMonth ? { day: 'numeric' } : { day: 'numeric', month: 'short' })
    return `${from} – ${last.toLocaleDateString('es', { day: 'numeric', month: 'short', year: 'numeric' })}`
  }
  if (view.value === 'day') {
    return anchor.value.toLocaleDateString('es', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
  }
  return monthStart.value.toLocaleDateString('es', { month: 'long', year: 'numeric' })
})()))

const providers = computed(() => [...new Set(items.value.flatMap((i) => i.providers))].sort())

const visible = computed(() => items.value.filter((i) =>
  (!providerFilter.value || i.providers.includes(providerFilter.value))
  && (!statusFilter.value || i.status === statusFilter.value),
))

const byDay = computed(() => {
  const map: Record<string, CalendarItem[]> = {}
  for (const item of visible.value) {
    if (!item.scheduled_at) continue
    ;(map[dayKey(new Date(item.scheduled_at))] ??= []).push(item)
  }
  return map
})

const listDays = computed(() => Object.keys(byDay.value).sort())
const todayKey = dayKey(new Date())
const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone
const brandTimezone = computed(() => auth.brands.find((b) => b.id === brandId.value)?.timezone ?? null)

function time(iso: string | null): string {
  return iso ? new Date(iso).toLocaleTimeString('es', { hour: '2-digit', minute: '2-digit' }) : ''
}

function dayLabel(key: string): string {
  return (parseDay(key) ?? new Date()).toLocaleDateString('es', { weekday: 'long', day: 'numeric', month: 'long' })
}

// --- Mejores horarios (Semana y Día; analítica avanzada) ---
const BEST_TIMES_KEY = 'loop7.calendar.bestTimes'
const { data: bestData, available: bestAvailable, load: loadBest, clear: clearBest } = useBestTimes()

function readShowBest(): boolean {
  try {
    return localStorage.getItem(BEST_TIMES_KEY) !== 'off'
  } catch {
    return true
  }
}

const showBest = ref(readShowBest())
watch(showBest, (on) => {
  try {
    localStorage.setItem(BEST_TIMES_KEY, on ? 'on' : 'off')
  } catch {
    // Sin almacenamiento del navegador la preferencia dura sólo esta visita.
  }
})

/** Fechas recomendadas del rango visible, por celda en la hora local del navegador. */
const highlights = computed<Record<string, CalendarHighlight>>(() => {
  const map: Record<string, CalendarHighlight> = {}
  if (!showBest.value) return map
  for (const o of bestData.value?.occurrences ?? []) {
    const d = new Date(o.at)
    map[`${dayKey(d)}-${d.getHours()}`] = { lift: o.lift }
  }
  return map
})

function loadBestTimes(): void {
  if (!showBest.value || (view.value !== 'week' && view.value !== 'day')) {
    clearBest()
    return
  }
  const from = new Date(range.value[0])
  const to = new Date(range.value[range.value.length - 1])
  to.setHours(23, 59, 59, 999)
  loadBest(brandId.value, { providers: providerFilter.value ? [providerFilter.value] : [], from, to })
}

let requestId = 0

async function load(): Promise<void> {
  if (!brandId.value) return
  // Al avanzar rápido las respuestas pueden llegar desordenadas: sólo cuenta la última.
  const current = ++requestId
  loading.value = true
  failed.value = false
  const from = new Date(range.value[0])
  const to = new Date(range.value[range.value.length - 1])
  to.setHours(23, 59, 59, 999)
  try {
    const requests: Promise<{ data: { data: unknown } }>[] = [
      http.get(`/brands/${brandId.value}/calendar`, { params: { from: from.toISOString(), to: to.toISOString() } }),
    ]
    if (canSchedule) requests.push(http.get(`/brands/${brandId.value}/content`, { params: { status: 'approved', per_page: 50 } }))
    const [cal, approved] = await Promise.all(requests)
    if (current !== requestId) return
    items.value = cal.data.data as CalendarItem[]
    ready.value = approved ? (approved.data.data as ReadyItem[]) : []
  } catch {
    if (current === requestId) failed.value = true
  } finally {
    if (current === requestId) loading.value = false
  }
}

function shift(step: number): void {
  const d = anchor.value
  if (view.value === 'week') anchor.value = new Date(d.getFullYear(), d.getMonth(), d.getDate() + 7 * step)
  else if (view.value === 'day') anchor.value = new Date(d.getFullYear(), d.getMonth(), d.getDate() + step)
  // Mes: al día 1 para no saltar meses cortos (31 de enero + 1 mes).
  else anchor.value = new Date(d.getFullYear(), d.getMonth() + step, 1)
}

function goToday(): void {
  anchor.value = startOfDay(new Date())
}

function openDay(day: Date): void {
  anchor.value = startOfDay(day)
  view.value = 'day'
}

// --- Arrastrar para (re)programar ---
function onDragStart(event: DragEvent, id: string, kind: 'scheduled' | 'ready'): void {
  dragging.value = { id, kind }
  event.dataTransfer?.setData('text/plain', id)
  if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move'
}

function onDragEnd(): void {
  dragging.value = null
  dropTarget.value = null
}

/**
 * Nueva fecha al soltar. En el mes se conserva la hora al reprogramar y lo
 * aprobado sale a las 10:00; en semana/día se usa la hora de la celda
 * (conservando los minutos al reprogramar). Si esa fecha ya pasó pero la
 * celda (o el día, en el mes) aún no termina, sale en la siguiente hora en
 * punto; en una celda pasada no se programa.
 */
function targetDate(slot: Date, withHour: boolean): Date | null {
  const drag = dragging.value
  if (!drag) return null
  const date = new Date(slot)
  const current = items.value.find((i) => i.id === drag.id)?.scheduled_at
  const minutes = drag.kind === 'scheduled' && current ? new Date(current).getMinutes() : 0
  if (withHour) {
    date.setMinutes(minutes, 0, 0)
  } else if (drag.kind === 'scheduled' && current) {
    const t = new Date(current)
    date.setHours(t.getHours(), t.getMinutes(), 0, 0)
  } else {
    date.setHours(10, 0, 0, 0)
  }

  const minimum = new Date(Date.now() + 10 * 60_000)
  if (date < minimum) {
    const slotEnd = new Date(slot)
    slotEnd.setHours(withHour ? slot.getHours() + 1 : 24, 0, 0, 0)
    if (slotEnd <= new Date()) return null
    date.setTime(minimum.getTime())
    date.setMinutes(0, 0, 0)
    date.setHours(date.getHours() + 1)
  }
  return date
}

async function schedule(slot: Date, withHour: boolean): Promise<void> {
  const drag = dragging.value
  dropTarget.value = null
  if (!drag) return
  const when = targetDate(slot, withHour)
  dragging.value = null
  if (!when) {
    toasts.error('No se puede programar en el pasado.')
    return
  }
  try {
    await http.post(`/content/${drag.id}/schedule`, { scheduled_at: when.toISOString() })
    toasts.success(`${drag.kind === 'ready' ? 'Programado' : 'Reprogramado'} para el ${when.toLocaleString('es', { dateStyle: 'medium', timeStyle: 'short' })}.`)
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

watch([view, anchor], () => {
  const vista = VIEW_PARAMS[view.value]
  const fecha = dayKey(anchor.value)
  if (route.query.vista !== vista || route.query.fecha !== fecha) {
    router.replace({ query: { ...route.query, vista, fecha } })
  }
})
// La URL manda: si cambia desde fuera (p. ej. el enlace del menú), la vista la sigue.
// Al salir del calendario la ruta ya es otra: no se toca su URL.
watch(() => route.query, (query) => {
  if (route.name !== 'calendar') return
  const next = viewFromParam(query.vista)
  const day = parseDay(query.fecha) ?? startOfDay(new Date())
  if (next !== view.value) view.value = next
  if (dayKey(day) !== dayKey(anchor.value)) anchor.value = day
})
// Se recarga sólo si cambia el rango consultado (p. ej. no al pasar de Mes a Lista).
watch(() => `${brandId.value}|${dayKey(range.value[0])}|${range.value.length}`, load)
watch(() => `${brandId.value}|${dayKey(range.value[0])}|${view.value}|${providerFilter.value}|${showBest.value}`, loadBestTimes)
onMounted(() => {
  load()
  loadBestTimes()
})
</script>

<template>
  <div>
    <PageHeader title="Calendario" description="Lo programado y lo publicado de cada marca, por mes, semana o día.">
      <template #actions>
        <BrandPicker v-model="brandId" />
      </template>
    </PageHeader>

    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
      <div class="flex items-center gap-1">
        <button class="btn-ghost px-2" :aria-label="PREV[view]" :title="PREV[view]" @click="shift(-1)"><AppIcon name="chevron-left" :size="18" /></button>
        <span class="min-w-44 text-center font-semibold text-slate-800 dark:text-slate-100" aria-live="polite">{{ periodLabel }}</span>
        <button class="btn-ghost px-2" :aria-label="NEXT[view]" :title="NEXT[view]" @click="shift(1)"><AppIcon name="chevron-right" :size="18" /></button>
        <button class="btn-secondary ml-2 px-3 py-1.5 text-xs" @click="goToday">Hoy</button>
      </div>
      <div class="inline-flex rounded-lg border border-slate-200 bg-white p-1 text-sm dark:border-slate-800 dark:bg-slate-900" role="tablist" aria-label="Vista">
        <button
          v-for="v in VIEWS"
          :key="v[0]"
          role="tab"
          :aria-selected="view === v[0]"
          class="rounded-md px-3 py-1 font-medium transition"
          :class="view === v[0] ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'"
          @click="view = v[0]"
        >
          {{ v[1] }}
        </button>
      </div>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-2 text-sm">
      <label for="cal-provider" class="sr-only">Red social</label>
      <select id="cal-provider" v-model="providerFilter" class="input w-auto py-1.5 text-sm">
        <option value="">Todas las redes</option>
        <option v-for="p in providers" :key="p" :value="p">{{ providerName(p) }}</option>
      </select>
      <label for="cal-status" class="sr-only">Estado</label>
      <select id="cal-status" v-model="statusFilter" class="input w-auto py-1.5 text-sm">
        <option value="">Todos los estados</option>
        <option v-for="s in STATUS_OPTIONS" :key="s[0]" :value="s[0]">{{ s[1] }}</option>
      </select>
      <button v-if="providerFilter || statusFilter" type="button" class="btn-ghost text-xs" @click="providerFilter = ''; statusFilter = ''">Quitar filtros</button>
      <template v-if="bestAvailable && (view === 'week' || view === 'day')">
        <label class="inline-flex items-center gap-1.5 text-xs text-slate-600 dark:text-slate-300">
          <input v-model="showBest" type="checkbox" class="rounded border-slate-300 text-brand-600" />
          Mejores horarios
        </label>
        <span v-if="showBest && bestData && !bestData.sufficient" class="text-xs text-slate-400">
          Faltan datos: {{ bestData.sample }} de {{ bestData.min_posts }} publicaciones medidas.
        </span>
      </template>
      <span class="ml-auto text-xs text-slate-400" :title="brandTimezone && brandTimezone !== timezone ? `La marca está configurada en ${brandTimezone}` : undefined">
        Horas en tu zona horaria ({{ timezone }})
      </span>
    </div>

    <EmptyState v-if="!brandId" icon="brands" title="Crea una marca primero" />
    <div v-else-if="loading && items.length === 0" class="card p-6"><div class="skeleton h-96 w-full" /></div>
    <ErrorState v-else-if="failed" @retry="load" />

    <div v-else class="grid grid-cols-1 gap-6" :class="canSchedule && ready.length ? 'xl:grid-cols-[1fr_16rem]' : ''">
      <!-- Mes -->
      <div v-if="view === 'month'" class="card overflow-hidden">
        <div class="grid grid-cols-7 border-b border-slate-100 bg-slate-50 text-center text-xs font-semibold uppercase text-slate-500 dark:border-slate-800 dark:bg-slate-900">
          <div v-for="w in WEEKDAYS" :key="w" class="py-2">{{ w }}</div>
        </div>
        <div class="grid grid-cols-7">
          <div
            v-for="d in monthDays"
            :key="dayKey(d)"
            class="min-h-28 border-b border-r border-slate-100 p-1.5 dark:border-slate-800"
            :class="[
              d.getMonth() !== monthStart.getMonth() ? 'bg-slate-50 dark:bg-slate-800/30' : '',
              dropTarget === dayKey(d) ? 'bg-brand-50 ring-2 ring-inset ring-brand-400 dark:bg-brand-950/40' : '',
            ]"
            @dragover.prevent="dragging && (dropTarget = dayKey(d))"
            @dragleave="dropTarget === dayKey(d) && (dropTarget = null)"
            @drop.prevent="schedule(d, false)"
          >
            <p class="mb-1 text-right text-xs">
              <button
                type="button"
                class="rounded-full px-1.5 py-0.5 transition hover:bg-slate-100 dark:hover:bg-slate-800"
                :class="dayKey(d) === todayKey ? 'bg-brand-600 font-bold text-white hover:bg-brand-700' : d.getMonth() !== monthStart.getMonth() ? 'text-slate-300 dark:text-slate-600' : 'text-slate-500'"
                :aria-label="`Ver el ${d.toLocaleDateString('es', { weekday: 'long', day: 'numeric', month: 'long' })}`"
                @click="openDay(d)"
              >
                {{ d.getDate() }}
              </button>
            </p>
            <ul class="space-y-1">
              <li v-for="item in byDay[dayKey(d)] ?? []" :key="item.id">
                <RouterLink
                  :to="`/app/content/${item.id}`"
                  class="block truncate rounded border px-1.5 py-0.5 text-[11px] leading-tight"
                  :class="[STATUS_STYLES[item.status] ?? 'border-slate-200 bg-white text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200', canSchedule && item.status === 'scheduled' ? 'cursor-grab' : '']"
                  :draggable="canSchedule && item.status === 'scheduled'"
                  :title="`${time(item.scheduled_at)} · ${item.title} · ${item.status_label}`"
                  @dragstart="onDragStart($event, item.id, 'scheduled')"
                  @dragend="onDragEnd"
                >
                  <span class="font-semibold">{{ time(item.scheduled_at) }}</span> {{ item.title }}
                </RouterLink>
              </li>
            </ul>
          </div>
        </div>
      </div>

      <!-- Semana y día -->
      <CalendarTimeGrid
        v-else-if="view === 'week' || view === 'day'"
        :days="range"
        :items="visible"
        :status-styles="STATUS_STYLES"
        :can-schedule="canSchedule"
        :dragging="dragging !== null"
        :highlights="highlights"
        @item-drag-start="(event, id) => onDragStart(event, id, 'scheduled')"
        @item-drag-end="onDragEnd"
        @drop="(date) => schedule(date, true)"
        @open-day="openDay"
      />

      <!-- Lista -->
      <div v-else>
        <EmptyState v-if="listDays.length === 0" icon="calendar" title="Nada en este mes" description="Programa contenido aprobado desde su detalle o arrastrándolo al calendario." />
        <div v-else class="space-y-5">
          <section v-for="key in listDays" :key="key">
            <h2 class="mb-2 text-sm font-semibold text-slate-500">{{ capitalize(dayLabel(key)) }}</h2>
            <div class="card divide-y divide-slate-100 dark:divide-slate-800">
              <RouterLink
                v-for="item in byDay[key]"
                :key="item.id"
                :to="`/app/content/${item.id}`"
                class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 transition hover:bg-slate-50 dark:hover:bg-slate-800/50"
              >
                <span class="flex min-w-0 items-center gap-2 text-sm">
                  <span class="text-slate-400">{{ time(item.scheduled_at) }}</span>
                  <span class="truncate font-medium text-slate-800 dark:text-slate-100">{{ item.title }}</span>
                  <span v-if="item.campaign" class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-500 dark:bg-slate-800">{{ item.campaign }}</span>
                </span>
                <span class="flex items-center gap-2">
                  <ProviderIcon v-for="p in item.providers" :key="p" :provider="p" :size="20" />
                  <span class="rounded-full border px-2 py-0.5 text-xs font-semibold" :class="STATUS_STYLES[item.status] ?? 'border-slate-200 text-slate-600'">{{ item.status_label }}</span>
                </span>
              </RouterLink>
            </div>
          </section>
        </div>
      </div>

      <!-- Listos para programar -->
      <aside v-if="canSchedule && ready.length" class="card h-fit p-4" aria-labelledby="ready-title">
        <h2 id="ready-title" class="text-sm font-semibold text-slate-900 dark:text-white">Listos para programar</h2>
        <p class="mb-3 mt-1 text-xs text-slate-500">
          Arrástralos a un día (salen a las 10:00) o, en Semana y Día, a la hora exacta. Luego puedes ajustarla en su detalle.
          <template v-if="Object.keys(highlights).length">Las horas con ★ son las que mejor le funcionan a esta marca.</template>
        </p>
        <ul class="space-y-1.5">
          <li v-for="r in ready" :key="r.id">
            <RouterLink
              :to="`/app/content/${r.id}`"
              class="block cursor-grab truncate rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 py-1.5 text-xs font-medium text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200"
              draggable="true"
              @dragstart="onDragStart($event, r.id, 'ready')"
              @dragend="onDragEnd"
            >
              {{ r.title }}
            </RouterLink>
          </li>
        </ul>
      </aside>
    </div>
  </div>
</template>
