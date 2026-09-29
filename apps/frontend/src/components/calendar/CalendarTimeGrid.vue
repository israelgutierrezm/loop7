<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import ProviderIcon from '@/components/social/ProviderIcon.vue'

/**
 * Rejilla horaria de las vistas Semana (7 columnas) y Día (1 columna): una
 * fila por hora, las publicaciones en la hora de su salida y huecos donde
 * soltar lo que se arrastra para programarlo a esa hora.
 */
export interface CalendarItem {
  id: string
  title: string
  status: string
  status_label: string
  scheduled_at: string | null
  providers: string[]
  campaign: string | null
}

const props = defineProps<{
  days: Date[]
  items: CalendarItem[]
  statusStyles: Record<string, string>
  canSchedule: boolean
  dragging: boolean
}>()

const emit = defineEmits<{
  itemDragStart: [event: DragEvent, id: string]
  itemDragEnd: []
  drop: [date: Date]
  openDay: [day: Date]
}>()

const HOURS = Array.from({ length: 24 }, (_, h) => h)
/** Hora a la que se desplaza la vista si el rango no tiene nada antes. */
const DEFAULT_START_HOUR = 7

const scroller = ref<HTMLElement | null>(null)
const dropCell = ref<string | null>(null)
const now = ref(new Date())
let timer: number | undefined

const detailed = computed(() => props.days.length === 1)
const columns = computed(() => ({ gridTemplateColumns: `3.5rem repeat(${props.days.length}, minmax(0, 1fr))` }))

function dayKey(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const todayKey = computed(() => dayKey(now.value))

/** Publicaciones por celda «día-hora», en orden de salida. */
const byCell = computed(() => {
  const map: Record<string, CalendarItem[]> = {}
  for (const item of props.items) {
    if (!item.scheduled_at) continue
    const d = new Date(item.scheduled_at)
    ;(map[`${dayKey(d)}-${d.getHours()}`] ??= []).push(item)
  }
  return map
})

function time(iso: string | null): string {
  return iso ? new Date(iso).toLocaleTimeString('es', { hour: '2-digit', minute: '2-digit' }) : ''
}

function hourLabel(h: number): string {
  return `${String(h).padStart(2, '0')}:00`
}

function dateAt(day: Date, hour: number): Date {
  const d = new Date(day)
  d.setHours(hour, 0, 0, 0)
  return d
}

function isPast(day: Date, hour: number): boolean {
  const end = dateAt(day, hour)
  end.setHours(hour + 1)
  return end <= now.value
}

/** Línea de «ahora» dentro de la celda de la hora en curso (porcentaje desde arriba). */
function nowOffset(day: Date, hour: number): number | null {
  if (dayKey(day) !== todayKey.value || now.value.getHours() !== hour) return null
  return (now.value.getMinutes() / 60) * 100
}

function onDragOver(key: string): void {
  if (props.dragging) dropCell.value = key
}

function onDrop(day: Date, hour: number): void {
  dropCell.value = null
  emit('drop', dateAt(day, hour))
}

/** Desplaza la vista a la primera publicación del rango (o a las 7:00). */
async function scrollToStart(): Promise<void> {
  await nextTick()
  const inRange = props.items
    .filter((i) => i.scheduled_at)
    .map((i) => new Date(i.scheduled_at as string).getHours())
  const hour = Math.max(0, Math.min(DEFAULT_START_HOUR, ...inRange) - 1)
  const container = scroller.value
  const row = container?.querySelector<HTMLElement>(`[data-hour="${hour}"]`)
  if (container && row) {
    // Posición de la fila dentro del contenedor (offsetTop es relativo a otro ancestro).
    container.scrollTop += row.getBoundingClientRect().top - container.getBoundingClientRect().top
  }
}

watch(() => props.days.map(dayKey).join(), scrollToStart)
onMounted(() => {
  scrollToStart()
  timer = window.setInterval(() => (now.value = new Date()), 60_000)
})
onBeforeUnmount(() => window.clearInterval(timer))
</script>

<template>
  <div class="card overflow-hidden">
    <div class="overflow-x-auto">
      <div :class="detailed ? '' : 'min-w-[46rem]'">
        <!-- Cabecera: días -->
        <div class="grid border-b border-slate-100 bg-slate-50 dark:border-slate-800 dark:bg-slate-900" :style="columns">
          <div />
          <button
            v-for="d in days"
            :key="dayKey(d)"
            type="button"
            class="border-l border-slate-100 py-2 text-center text-xs transition hover:bg-slate-100 dark:border-slate-800 dark:hover:bg-slate-800"
            :aria-label="`Ver el ${d.toLocaleDateString('es', { weekday: 'long', day: 'numeric', month: 'long' })}`"
            :disabled="detailed"
            @click="emit('openDay', d)"
          >
            <span class="block font-semibold uppercase text-slate-500">{{ d.toLocaleDateString('es', { weekday: 'short' }) }}</span>
            <span
              class="mt-0.5 inline-grid h-7 min-w-7 place-items-center rounded-full px-1 text-sm font-semibold"
              :class="dayKey(d) === todayKey ? 'bg-brand-600 text-white' : 'text-slate-800 dark:text-slate-100'"
            >
              {{ d.getDate() }}
            </span>
          </button>
        </div>

        <!-- Cuerpo: una fila por hora -->
        <div ref="scroller" class="max-h-[68vh] overflow-y-auto">
          <div v-for="h in HOURS" :key="h" class="grid" :style="columns" :data-hour="h">
            <div class="relative border-t border-transparent pr-2 text-right">
              <span class="relative -top-2 text-[11px] text-slate-400">{{ h === 0 ? '' : hourLabel(h) }}</span>
            </div>
            <div
              v-for="d in days"
              :key="dayKey(d)"
              class="relative min-h-12 border-l border-t border-slate-100 p-1 dark:border-slate-800"
              :class="[
                isPast(d, h) ? 'bg-slate-50/70 dark:bg-slate-900/40' : '',
                dropCell === `${dayKey(d)}-${h}` ? 'bg-brand-50 ring-2 ring-inset ring-brand-400 dark:bg-brand-950/40' : '',
              ]"
              :aria-label="`${d.toLocaleDateString('es', { weekday: 'long', day: 'numeric' })}, ${hourLabel(h)}`"
              @dragover.prevent="onDragOver(`${dayKey(d)}-${h}`)"
              @dragleave="dropCell === `${dayKey(d)}-${h}` && (dropCell = null)"
              @drop.prevent="onDrop(d, h)"
            >
              <div
                v-if="nowOffset(d, h) !== null"
                class="pointer-events-none absolute inset-x-0 z-10 border-t-2 border-rose-500"
                :style="{ top: `${nowOffset(d, h)}%` }"
                aria-hidden="true"
              >
                <span class="absolute -left-1 -top-[5px] h-2 w-2 rounded-full bg-rose-500" />
              </div>
              <ul class="space-y-1">
                <li v-for="item in byCell[`${dayKey(d)}-${h}`] ?? []" :key="item.id">
                  <RouterLink
                    :to="`/app/content/${item.id}`"
                    class="block rounded border px-1.5 py-1 text-[11px] leading-tight"
                    :class="[
                      statusStyles[item.status] ?? 'border-slate-200 bg-white text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200',
                      canSchedule && item.status === 'scheduled' ? 'cursor-grab' : '',
                    ]"
                    :draggable="canSchedule && item.status === 'scheduled'"
                    :title="`${time(item.scheduled_at)} · ${item.title} · ${item.status_label}`"
                    @dragstart="emit('itemDragStart', $event, item.id)"
                    @dragend="emit('itemDragEnd')"
                  >
                    <span class="flex items-center gap-1">
                      <span class="font-semibold">{{ time(item.scheduled_at) }}</span>
                      <span class="truncate">{{ item.title }}</span>
                    </span>
                    <span v-if="detailed" class="mt-1 flex flex-wrap items-center gap-1.5">
                      <ProviderIcon v-for="p in item.providers" :key="p" :provider="p" :size="16" />
                      <span class="text-[10px] opacity-80">{{ item.status_label }}</span>
                      <span v-if="item.campaign" class="rounded bg-white/60 px-1 text-[10px] dark:bg-slate-900/40">{{ item.campaign }}</span>
                    </span>
                  </RouterLink>
                </li>
              </ul>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
