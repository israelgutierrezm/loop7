import type { ActionStep, AutomationMeta, BranchStep, Flow, FlowStep, WaitStep } from '@/types/automations'

/**
 * Operaciones sobre el árbol de pasos del editor visual. Regla del modelo: una
 * condición es siempre el último paso de su lista (lo que va después vive en
 * sus caminos «Sí» y «No»).
 */

export interface StepLocation {
  list: FlowStep[]
  index: number
  step: FlowStep
}

/** Campos que configura cada tipo de acción. */
export interface ActionField {
  key: string
  label: string
  placeholder?: string
  kind: 'text' | 'textarea' | 'audience'
}

export const ACTION_FIELDS: Record<string, ActionField[]> = {
  notify: [
    { key: 'message', label: 'Mensaje del aviso', placeholder: 'Ej. Se publicó {content_title} en {brand}', kind: 'textarea' },
    { key: 'audience', label: 'Avisar a', kind: 'audience' },
  ],
  webhook: [{ key: 'url', label: 'URL del webhook (POST con los datos del disparador)', placeholder: 'https://tu-servidor.com/hook', kind: 'text' }],
  create_draft: [
    { key: 'title', label: 'Título del borrador', placeholder: 'Ej. {title}', kind: 'text' },
    { key: 'body', label: 'Texto del borrador (opcional)', placeholder: 'Ej. {summary}\n\nLee más: {link}', kind: 'textarea' },
  ],
  inbox_reply: [{ key: 'message', label: 'Respuesta automática', placeholder: 'Ej. Hola {participant}, gracias por escribir.', kind: 'textarea' }],
  inbox_tag: [{ key: 'tag', label: 'Etiqueta', placeholder: 'Ej. urgente', kind: 'text' }],
}

export function newStepId(flow?: Flow): string {
  const taken = new Set(flow ? allSteps(flow.steps).map((s) => s.id) : [])
  let id = ''
  do {
    id = `s_${Math.random().toString(36).slice(2, 10)}`
  } while (taken.has(id))
  return id
}

export function newAction(action: string, flow?: Flow): ActionStep {
  return { id: newStepId(flow), type: 'action', action, config: action === 'notify' ? { message: '', audience: 'managers' } : {} }
}

export function newWait(flow?: Flow): WaitStep {
  return { id: newStepId(flow), type: 'wait', amount: 1, unit: 'hours' }
}

export function newBranch(field: string, flow?: Flow): BranchStep {
  return { id: newStepId(flow), type: 'branch', match: 'all', conditions: [{ field, operator: 'contains', value: '' }], yes: [], no: [] }
}

/** Todos los pasos, aplanados. */
export function allSteps(steps: FlowStep[]): FlowStep[] {
  return steps.flatMap((s) => (s.type === 'branch' ? [s, ...allSteps(s.yes), ...allSteps(s.no)] : [s]))
}

export function locate(steps: FlowStep[], id: string): StepLocation | null {
  for (let index = 0; index < steps.length; index++) {
    const step = steps[index]
    if (step.id === id) return { list: steps, index, step }
    if (step.type === 'branch') {
      const found = locate(step.yes, id) ?? locate(step.no, id)
      if (found) return found
    }
  }
  return null
}

/** ¿`list` está dentro de la condición `branch` (en cualquier nivel)? */
export function containsList(branch: BranchStep, list: FlowStep[]): boolean {
  if (branch.yes === list || branch.no === list) return true
  return [...branch.yes, ...branch.no].some((s) => s.type === 'branch' && containsList(s, list))
}

/**
 * Inserta un paso en la posición indicada. Una condición en medio de una lista
 * se lleva los pasos siguientes a su camino «Sí».
 */
export function insertStep(list: FlowStep[], index: number, step: FlowStep): void {
  if (step.type === 'branch') {
    const following = list.splice(index)
    step.yes.push(...following)
    list.push(step)
    return
  }
  // Después de una condición no puede haber pasos: se insertan antes.
  const last = list[list.length - 1]
  const max = last?.type === 'branch' ? list.length - 1 : list.length
  list.splice(Math.min(index, max), 0, step)
}

/** Mueve un paso a otra posición (arrastrar y soltar). */
export function moveStepTo(flow: Flow, id: string, target: FlowStep[], index: number): boolean {
  const from = locate(flow.steps, id)
  if (!from) return false
  // Una condición no puede ir dentro de sus propios caminos.
  if (from.step.type === 'branch' && containsList(from.step, target)) return false

  from.list.splice(from.index, 1)
  const at = from.list === target && from.index < index ? index - 1 : index
  insertStep(target, at, from.step)
  return true
}

/** Sube o baja un paso (acción o espera) dentro de su lista, sin pasar una condición. */
export function canMove(location: StepLocation, direction: -1 | 1): boolean {
  if (location.step.type === 'branch') return false
  const neighbour = location.list[location.index + direction]
  return neighbour !== undefined && neighbour.type !== 'branch'
}

export function moveStep(location: StepLocation, direction: -1 | 1): void {
  if (!canMove(location, direction)) return
  const { list, index } = location
  ;[list[index], list[index + direction]] = [list[index + direction], list[index]]
}

/** Copia de una acción o espera, justo después. */
export function duplicateStep(flow: Flow, location: StepLocation): FlowStep | null {
  if (location.step.type === 'branch') return null
  // JSON y no structuredClone: el paso es un proxy reactivo.
  const copy: FlowStep = { ...(JSON.parse(JSON.stringify(location.step)) as FlowStep), id: newStepId(flow) }
  location.list.splice(location.index + 1, 0, copy)
  return copy
}

/** Quita un paso; de una condición puede conservarse lo que había en «Sí». */
export function removeStep(location: StepLocation, keepYes = false): void {
  const { list, index, step } = location
  const replacement = step.type === 'branch' && keepYes ? step.yes : []
  list.splice(index, 1, ...replacement)
}

export function countSteps(steps: FlowStep[]): number {
  return allSteps(steps).length
}

// --- Textos de los nodos ---

function truncate(text: string, max = 70): string {
  const clean = text.replace(/\s+/g, ' ').trim()
  return clean.length > max ? `${clean.slice(0, max - 1)}…` : clean
}

export function waitLabel(step: WaitStep, meta: AutomationMeta | null): string {
  const units: Record<string, [string, string]> = { minutes: ['minuto', 'minutos'], hours: ['hora', 'horas'], days: ['día', 'días'] }
  const [one, many] = units[step.unit] ?? [step.unit, meta?.wait_units.find((u) => u.value === step.unit)?.label ?? step.unit]
  return `${step.amount} ${step.amount === 1 ? one : many}`
}

export function stepTitle(step: FlowStep, meta: AutomationMeta | null): string {
  if (step.type === 'wait') return `Esperar ${waitLabel(step, meta)}`
  if (step.type === 'branch') return 'Condición'
  return meta?.actions.find((a) => a.value === step.action)?.label ?? 'Elige una acción'
}

export function stepSummary(step: FlowStep, meta: AutomationMeta | null): string {
  if (step.type === 'wait') return 'Después sigue con el paso siguiente.'
  if (step.type === 'branch') return conditionSummary(step, meta)
  const config = step.config ?? {}
  const main = config.message || config.url || config.title || config.tag || ''
  return main ? truncate(main) : 'Sin configurar'
}

export function conditionSummary(step: BranchStep, meta: AutomationMeta | null): string {
  const first = step.conditions[0]
  if (!first || !first.field) return 'Sin configurar'
  const operator = meta?.operators.find((o) => o.value === first.operator)
  const value = operator?.needs_value === false ? '' : ` «${truncate(first.value, 30)}»`
  const rest = step.conditions.length - 1
  const joiner = step.match === 'any' ? 'o' : 'y'
  return `Si ${first.field} ${operator?.label ?? first.operator}${value}${rest > 0 ? ` ${joiner} ${rest} más` : ''}`
}
