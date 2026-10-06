/** Formatos de números para la analítica. */

const compact = new Intl.NumberFormat('es', { notation: 'compact', maximumFractionDigits: 1 })
const plain = new Intl.NumberFormat('es', { maximumFractionDigits: 1 })

export function formatCompact(value: number | null | undefined): string {
  return value === null || value === undefined ? '—' : compact.format(value)
}

export function formatNumber(value: number | null | undefined): string {
  return value === null || value === undefined ? '—' : plain.format(value)
}

/** «+12 %», «-3,5 %»; con `signed` false, sin signo. */
export function formatPercent(value: number | null | undefined, signed = true): string {
  if (value === null || value === undefined) return '—'
  const sign = signed && value > 0 ? '+' : ''
  return `${sign}${plain.format(value)} %`
}

export function formatSigned(value: number | null | undefined): string {
  if (value === null || value === undefined) return '—'
  return `${value > 0 ? '+' : ''}${compact.format(value)}`
}
