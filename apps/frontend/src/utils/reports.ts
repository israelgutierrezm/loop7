import type { ClientReport } from '@/types/reports'

export const WEEKDAYS = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo']

export function hourLabel(hour: number): string {
  return `${String(hour).padStart(2, '0')}:00`
}

/** «Cada lunes a las 08:00», «El día 1 de cada mes a las 08:00» o «Sin envío automático». */
export function scheduleLabel(report: Pick<ClientReport, 'frequency' | 'send_day' | 'send_hour'>): string {
  if (!report.frequency || report.send_day === null) return 'Sin envío automático'
  const hour = hourLabel(report.send_hour)
  return report.frequency === 'weekly'
    ? `Cada ${WEEKDAYS[report.send_day - 1] ?? ''} a las ${hour}`
    : `El día ${report.send_day} de cada mes a las ${hour}`
}

/** «1–30 sep 2026», «28 sep – 4 oct 2026»… */
export function periodRange(from: string, to: string): string {
  const a = new Date(`${from}T12:00:00`)
  const b = new Date(`${to}T12:00:00`)
  const day = (d: Date) => d.toLocaleDateString('es', { day: 'numeric' })
  const dayMonth = (d: Date) => d.toLocaleDateString('es', { day: 'numeric', month: 'short' })
  const full = (d: Date) => d.toLocaleDateString('es', { day: 'numeric', month: 'short', year: 'numeric' })
  if (from === to) return full(a)
  if (a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth()) return `${day(a)}–${full(b)}`
  if (a.getFullYear() === b.getFullYear()) return `${dayMonth(a)} – ${full(b)}`
  return `${full(a)} – ${full(b)}`
}

/** Correo con forma razonable (el backend valida de verdad). */
export function looksLikeEmail(value: string): boolean {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)
}
