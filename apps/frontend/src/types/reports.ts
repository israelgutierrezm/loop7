/** Informes para clientes (docs/05). */

export type ReportFrequency = 'weekly' | 'monthly'
export type RunStatus = 'pending' | 'ready' | 'failed'
export type RunTrigger = 'manual' | 'send' | 'scheduled'

export interface ReportRun {
  id: string
  period_from: string
  period_to: string
  trigger: RunTrigger
  status: RunStatus
  error: string | null
  file_size: number | null
  recipients: string[]
  delivered: number
  sent_at: string | null
  created_at: string
  summary: { followers: number | null; reach: number | null; engagement: number | null; posts: number | null } | null
  /** Enlace firmado (30 min) al PDF; null hasta que está listo. */
  download_url: string | null
}

export interface ClientReport {
  id: string
  name: string
  period: string
  period_label: string
  sections: string[]
  intro: string | null
  recipients: string[]
  frequency: ReportFrequency | null
  send_day: number | null
  send_hour: number
  timezone: string
  next_send_at: string | null
  last_sent_at: string | null
  created_at: string | null
  runs: ReportRun[]
}

export interface ReportSectionOption {
  value: string
  label: string
  available: boolean
}

export interface ReportsIndex {
  reports: ClientReport[]
  usage: { used: number; limit: number }
  sections: ReportSectionOption[]
  periods: { value: string; label: string }[]
  timezone: string
}

export interface ReportPayload {
  name: string
  period: string
  sections: string[]
  intro: string | null
  recipients: string[]
  frequency: ReportFrequency | null
  send_day: number | null
  send_hour: number
}
