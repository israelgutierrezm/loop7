/** Formatos compartidos (es) para importes, fechas y tamaños. */

export function money(cents: number | null | undefined, currency = 'USD'): string {
  if (cents === null || cents === undefined) return '—'
  try {
    return new Intl.NumberFormat('es', { style: 'currency', currency }).format(cents / 100)
  } catch {
    return `${(cents / 100).toFixed(2)} ${currency}`
  }
}

export function date(iso: string | null | undefined): string {
  return iso ? new Date(iso).toLocaleDateString('es', { dateStyle: 'medium' }) : '—'
}

export function dateLong(iso: string | null | undefined): string {
  return iso ? new Date(iso).toLocaleDateString('es', { dateStyle: 'long' }) : '—'
}

export function dateTime(iso: string | null | undefined): string {
  return iso ? new Date(iso).toLocaleString('es', { dateStyle: 'medium', timeStyle: 'short' }) : '—'
}

/** "hace 5 min", "hace 3 h", "hace 2 días"; más de una semana → fecha. */
export function relativeTime(iso: string | null | undefined): string {
  if (!iso) return '—'
  const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000)
  const abs = Math.abs(seconds)
  if (abs < 45) return 'ahora'
  const rtf = new Intl.RelativeTimeFormat('es', { numeric: 'auto', style: 'short' })
  if (abs < 3600) return rtf.format(Math.round(seconds / 60), 'minute')
  if (abs < 86400) return rtf.format(Math.round(seconds / 3600), 'hour')
  if (abs < 7 * 86400) return rtf.format(Math.round(seconds / 86400), 'day')
  return date(iso)
}

export function bytes(value: number): string {
  if (value < 1024) return `${value} B`
  if (value < 1024 ** 2) return `${(value / 1024).toFixed(0)} KB`
  if (value < 1024 ** 3) return `${(value / 1024 ** 2).toFixed(1)} MB`
  return `${(value / 1024 ** 3).toFixed(2)} GB`
}

/** Límite de plan legible: -1 = ilimitado. */
export function limit(value: number | boolean | undefined): string {
  if (value === -1) return 'Ilimitado'
  if (typeof value === 'boolean') return value ? 'Sí' : 'No'
  return String(value ?? 0)
}

export function intervalLabel(interval: string | null | undefined): string {
  return interval === 'year' ? 'anual' : interval === 'month' ? 'mensual' : '—'
}
