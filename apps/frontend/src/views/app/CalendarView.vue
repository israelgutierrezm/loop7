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
import AppIcon from '@/components/AppIcon.vue'
import ProviderIcon from '@/components/social/ProviderIcon.vue'

interface CalendarItem {
  id: string
  title: string
  status: string
  status_label: string
  scheduled_at: string | null
  providers: string[]
  campaign: string | null
}
interface ReadyItem { id: string; title: string }

const STATUS_STYLES: Record<string, string> = {
  scheduled: 'border-brand-200 bg-brand-50 text-brand-800 dark:border-brand-900 dark:bg-brand-950/40 dark:text-brand-200',
  publishing: 'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-200',
  published: 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200',
  partial: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200',
  failed: 'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200',
}
const WEEKDAYS = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom']

const auth = useAuthStore()
const toasts = useToastStore()
const canSchedule = auth.can('content.schedule')

const brandId = ref<string | null>(auth.brands[0]?.id ?? null)
const cursor = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1))
const view = ref<'month' | 'list'>('month')
const items = ref<CalendarItem[]>([])
const ready = ref<ReadyItem[]>([])
const loading = ref(false)
const failed = ref(false)
const dragging = ref<{ id: string; kind: 'scheduled' | 'ready' } | null>(null)
const dropTarget = ref<string | null>(null)

const monthLabel = computed(() => cursor.value.toLocaleDateString('es', { month: 'long', year: 'numeric' }))

function dayKey(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/** Semanas visibles (de lunes a domingo) que cubren el mes. */
const days = computed(() => {
  const first = new Date(cursor.value)
  const start = new Date(first)
  start.setDate(first.getDate() - ((first.getDay() + 6) % 7))
  const last = new Date(first.getFullYear(), first.getMonth() + 1, 0)
  const end = new Date(last)
  end.setDate(last.getDate() + (6 - ((last.getDay() + 6) % 7)))
  const list: Date[] = []
  for (const d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) list.push(new Date(d))
  return list
})

const byDay = computed(() => {
  const map: Record<string, CalendarItem[]> = {}
  for (const item of items.value) {
    if (!item.scheduled_at) continue
    ;(map[dayKey(new Date(item.scheduled_at))] ??= []).push(item)
  }
  return map
})

const todayKey = dayKey(new Date())

function time(iso: string | null): string {
  return iso ? new Date(iso).toLocaleTimeString('es', { hour: '2-digit', minute: '2-digit' }) : ''
}

function dayLabel(key: string): string {
  const [y, m, d] = key.split('-').map(Number)
  return new Date(y, m - 1, d).toLocaleDateString('es', { weekday: 'long', day: 'numeric', month: 'long' })
}

async function load(): Promise<void> {
  if (!brandId.value) return
  loading.value = true
  failed.value = false
  const range = days.value
  const from = new Date(range[0])
  const to = new Date(range[range.length - 1])
  to.setHours(23, 59, 59, 999)
  try {
    const requests: Promise<{ data: { data: unknown } }>[] = [
      http.get(`/brands/${brandId.value}/calendar`, { params: { from: from.toISOString(), to: to.toISOString() } }),
    ]
    if (canSchedule) requests.push(http.get(`/brands/${brandId.value}/content`, { params: { status: 'approved', per_page: 50 } }))
    const [cal, approved] = await Promise.all(requests)
    items.value = cal.data.data as CalendarItem[]
    ready.value = approved ? (approved.data.data as ReadyItem[]) : []
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

function shift(months: number): void {
  cursor.value = new Date(cursor.value.getFullYear(), cursor.value.getMonth() + months, 1)
}

function goToday(): void {
  cursor.value = new Date(new Date().getFullYear(), new Date().getMonth(), 1)
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

/** Nueva fecha: conserva la hora al reprogramar; los aprobados sin fecha salen a las 10:00. */
function targetDate(day: Date): Date | null {
  const drag = dragging.value
  if (!drag) return null
  const date = new Date(day)
  const current = items.value.find((i) => i.id === drag.id)?.scheduled_at
  if (drag.kind === 'scheduled' && current) {
    const t = new Date(current)
    date.setHours(t.getHours(), t.getMinutes(), 0, 0)
  } else {
    date.setHours(10, 0, 0, 0)
  }
  const minimum = new Date(Date.now() + 10 * 60_000)
  if (date < minimum) {
    // Hoy, pasada esa hora: a la siguiente hora en punto.
    if (dayKey(date) !== todayKey) return null
    date.setTime(minimum.getTime())
    date.setMinutes(0, 0, 0)
    date.setHours(date.getHours() + 1)
  }
  return date
}

async function onDrop(day: Date): Promise<void> {
  const drag = dragging.value
  dropTarget.value = null
  if (!drag) return
  const when = targetDate(day)
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

watch(brandId, load)
watch(cursor, load)
onMounted(load)
</script>

<template>
  <div>
    <PageHeader title="Calendario" description="Lo programado y lo publicado de cada marca, día a día.">
      <template #actions>
        <BrandPicker v-model="brandId" />
      </template>
    </PageHeader>

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <div class="flex items-center gap-1">
        <button class="btn-ghost px-2" aria-label="Mes anterior" @click="shift(-1)"><AppIcon name="chevron-left" :size="18" /></button>
        <span class="min-w-44 text-center font-semibold capitalize text-slate-800 dark:text-slate-100" aria-live="polite">{{ monthLabel }}</span>
        <button class="btn-ghost px-2" aria-label="Mes siguiente" @click="shift(1)"><AppIcon name="chevron-right" :size="18" /></button>
        <button class="btn-secondary ml-2 px-3 py-1.5 text-xs" @click="goToday">Hoy</button>
      </div>
      <div class="inline-flex rounded-lg border border-slate-200 bg-white p-1 text-sm dark:border-slate-800 dark:bg-slate-900" role="tablist" aria-label="Vista">
        <button
          v-for="v in ([['month', 'Mes'], ['list', 'Lista']] as const)"
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
            v-for="d in days"
            :key="dayKey(d)"
            class="min-h-28 border-b border-r border-slate-100 p-1.5 dark:border-slate-800"
            :class="[
              d.getMonth() !== cursor.getMonth() ? 'bg-slate-50/60 dark:bg-slate-900/40' : '',
              dropTarget === dayKey(d) ? 'bg-brand-50 ring-2 ring-inset ring-brand-400 dark:bg-brand-950/40' : '',
            ]"
            @dragover.prevent="dragging && (dropTarget = dayKey(d))"
            @dragleave="dropTarget === dayKey(d) && (dropTarget = null)"
            @drop.prevent="onDrop(d)"
          >
            <p class="mb-1 text-right text-xs" :class="dayKey(d) === todayKey ? 'font-bold text-brand-600' : d.getMonth() !== cursor.getMonth() ? 'text-slate-300 dark:text-slate-600' : 'text-slate-500'">
              <span :class="dayKey(d) === todayKey ? 'rounded-full bg-brand-600 px-1.5 py-0.5 text-white' : ''">{{ d.getDate() }}</span>
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

      <!-- Lista -->
      <div v-else>
        <EmptyState v-if="items.length === 0" icon="calendar" title="Nada en este mes" description="Programa contenido aprobado desde su detalle o arrastrándolo al calendario." />
        <div v-else class="space-y-5">
          <section v-for="(dayItems, key) in byDay" :key="key">
            <h2 class="mb-2 text-sm font-semibold capitalize text-slate-500">{{ dayLabel(String(key)) }}</h2>
            <div class="card divide-y divide-slate-100 dark:divide-slate-800">
              <RouterLink
                v-for="item in dayItems"
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
        <p class="mb-3 mt-1 text-xs text-slate-500">Arrástralos a un día (salen a las 10:00; luego puedes ajustar la hora en su detalle).</p>
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
