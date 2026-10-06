// Automatizaciones (docs/05): un disparador y un flujo de pasos que se edita en el editor visual.

export type StepType = 'action' | 'wait' | 'branch'
export type WaitUnit = 'minutes' | 'hours' | 'days'
export type ConditionMatch = 'all' | 'any'

export interface Condition {
  field: string
  operator: string
  value: string
}

export interface ActionStep {
  id: string
  type: 'action'
  action: string
  config: Record<string, string>
}

export interface WaitStep {
  id: string
  type: 'wait'
  amount: number
  unit: WaitUnit
}

export interface BranchStep {
  id: string
  type: 'branch'
  match: ConditionMatch
  conditions: Condition[]
  yes: FlowStep[]
  no: FlowStep[]
}

export type FlowStep = ActionStep | WaitStep | BranchStep

export interface Flow {
  steps: FlowStep[]
}

export type RunStatus = 'running' | 'waiting' | 'success' | 'failed' | 'skipped' | 'cancelled'

/** Lo que pasó (o pasaría, al probar) en un paso. */
export interface TraceEntry {
  id: string
  type: StepType
  status: RunStatus
  message: string
  path?: 'yes' | 'no'
  preview?: { label: string; value: string }[]
  at?: string
}

export interface AutomationRun {
  id: string
  status: RunStatus
  message: string | null
  created_at: string | null
  resume_at: string | null
  steps: TraceEntry[]
}

export interface FeedState {
  title: string | null
  last_polled_at: string | null
  last_error: string | null
  ready: boolean
}

export interface Automation {
  id: string
  name: string
  is_enabled: boolean
  trigger: string
  trigger_label: string
  trigger_config: { feed_url?: string }
  inbound_url: string | null
  feed: FeedState | null
  brand: string | null
  brand_name: string | null
  flow: Flow
  steps_count: number
  actions_count: number
  run_count: number
  last_run_at: string | null
  runs?: AutomationRun[]
  fields?: string[]
}

export interface Option {
  value: string
  label: string
}

export interface AutomationMeta {
  triggers: (Option & { description: string; fields: string[]; examples: Record<string, string>; external: boolean })[]
  actions: (Option & { triggers: string[] | null })[]
  operators: (Option & { needs_value: boolean })[]
  matches: Option[]
  wait_units: Option[]
  audiences: Option[]
  limits: {
    max_steps: number
    max_actions: number
    max_waits: number
    max_wait_days: number
    max_depth: number
    max_conditions: number
  }
  feed_poll_minutes: number
}

/** Datos de la regla que se editan junto al flujo. */
export interface AutomationForm {
  id: string
  name: string
  trigger: string
  feed_url: string
  brand: string
  is_enabled: boolean
}
