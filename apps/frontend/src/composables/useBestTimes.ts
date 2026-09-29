import { computed, ref } from 'vue'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'

/** Franja recomendada: día (1 = lunes … 7 = domingo) y hora en la zona de la marca. */
export interface BestTimeSlot {
  weekday: number
  hour: number
  /** Mejora sobre lo habitual, en %. */
  lift: number
  posts: number
}

/** Próxima fecha de una franja recomendada (instante absoluto, ISO 8601). */
export interface BestTimeOccurrence {
  at: string
  weekday: number
  hour: number
  lift: number
}

export interface BestTimes {
  timezone: string
  providers: string[]
  window_days: number
  sample: number
  min_posts: number
  sufficient: boolean
  /** 7 filas (lunes a domingo) × 24 horas: rendimiento frente a lo habitual (1 = igual; null = sin datos). */
  heatmap: (number | null)[][]
  counts: number[][]
  top: BestTimeSlot[]
  occurrences: BestTimeOccurrence[]
}

export interface BestTimesQuery {
  providers?: string[]
  from?: Date
  to?: Date
}

export const WEEKDAYS = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo']

/** «martes a las 10:00» (en la zona de la marca). */
export function slotLabel(weekday: number, hour: number): string {
  return `${WEEKDAYS[weekday - 1] ?? ''} a las ${String(hour).padStart(2, '0')}:00`
}

/**
 * Mejores horarios de una marca (docs/05). Sólo consulta si el plan incluye la
 * analítica avanzada y el usuario puede ver la analítica (el backend lo valida
 * igualmente); si llegan respuestas desordenadas, cuenta sólo la última.
 */
export function useBestTimes() {
  const auth = useAuthStore()
  const data = ref<BestTimes | null>(null)
  const loading = ref(false)
  const failed = ref(false)
  const available = computed(() => auth.hasFeature('feature.analytics_advanced') && auth.can('analytics.view'))
  let requestId = 0

  async function load(brandId: string | null, query: BestTimesQuery = {}): Promise<void> {
    const current = ++requestId
    if (!brandId || !available.value) {
      data.value = null
      loading.value = false
      return
    }
    loading.value = true
    failed.value = false
    try {
      const { data: res } = await http.get(`/brands/${brandId}/analytics/best-times`, {
        params: {
          providers: query.providers?.length ? query.providers : undefined,
          from: query.from?.toISOString(),
          to: query.to?.toISOString(),
        },
      })
      if (current === requestId) data.value = res.data as BestTimes
    } catch {
      if (current === requestId) {
        data.value = null
        failed.value = true
      }
    } finally {
      if (current === requestId) loading.value = false
    }
  }

  function clear(): void {
    requestId++
    data.value = null
    loading.value = false
  }

  return { data, loading, failed, available, load, clear }
}
