import { computed, inject, provide, reactive, ref, type ComputedRef, type InjectionKey, type Ref } from 'vue'
import { useConfirmStore } from '@/stores/confirm'
import { useToastStore } from '@/stores/toasts'
import type { AutomationForm, AutomationMeta, Flow, FlowStep, StepType, TraceEntry } from '@/types/automations'
import {
  allSteps,
  canMove,
  countSteps,
  duplicateStep,
  insertStep,
  locate,
  moveStep,
  moveStepTo,
  newAction,
  newBranch,
  newWait,
  removeStep,
} from '@/utils/automationFlow'

/**
 * Estado del editor visual compartido por el diagrama (recursivo) y el panel
 * de edición: la regla, su flujo, el paso seleccionado, los errores por paso y
 * la traza superpuesta (de una ejecución o de «Probar»).
 */
export interface FlowOverlay {
  kind: 'run' | 'test'
  label: string
  entries: Record<string, TraceEntry>
}

export interface FlowEditor {
  meta: Ref<AutomationMeta | null>
  form: AutomationForm
  flow: Flow
  selected: Ref<string | null>
  errors: Ref<Record<string, string[]>>
  overlay: Ref<FlowOverlay | null>
  dragging: Ref<string | null>
  knownFields: Ref<string[]>
  /** Variables del disparador y las que trajo la última ejecución. */
  fields: ComputedRef<string[]>
  select: (id: string | null) => void
  stepErrors: (id: string) => string[]
  fieldError: (id: string, field: string) => string | undefined
  canAdd: (type: StepType) => boolean
  add: (list: FlowStep[], index: number, type: StepType) => void
  remove: (id: string) => Promise<void>
  move: (id: string, direction: -1 | 1) => void
  canMoveStep: (id: string, direction: -1 | 1) => boolean
  duplicate: (id: string) => void
  drop: (list: FlowStep[], index: number) => void
}

const KEY: InjectionKey<FlowEditor> = Symbol('flow-editor')

export function createFlowEditor(): FlowEditor {
  const toasts = useToastStore()
  const confirm = useConfirmStore()

  const meta = ref<AutomationMeta | null>(null)
  const form = reactive<AutomationForm>({ id: '', name: '', trigger: 'content.published', feed_url: '', brand: '', is_enabled: true })
  const flow = reactive<Flow>({ steps: [] })
  const selected = ref<string | null>(null)
  const errors = ref<Record<string, string[]>>({})
  const overlay = ref<FlowOverlay | null>(null)
  const dragging = ref<string | null>(null)
  const knownFields = ref<string[]>([])

  const fields = computed(() => {
    const trigger = meta.value?.triggers.find((t) => t.value === form.trigger)
    return [...new Set([...(trigger?.fields ?? []), ...knownFields.value])]
  })

  function select(id: string | null): void {
    selected.value = id
  }

  function stepErrors(id: string): string[] {
    const prefix = `flow.${id}`
    return Object.entries(errors.value)
      .filter(([key]) => key === prefix || key.startsWith(`${prefix}.`))
      .flatMap(([, messages]) => messages)
  }

  function fieldError(id: string, field: string): string | undefined {
    return errors.value[`flow.${id}.${field}`]?.[0]
  }

  function canAdd(type: StepType): boolean {
    const limits = meta.value?.limits
    if (!limits) return true
    const steps = allSteps(flow.steps)
    if (steps.length >= limits.max_steps) return false
    if (type === 'action') return steps.filter((s) => s.type === 'action').length < limits.max_actions
    if (type === 'wait') return steps.filter((s) => s.type === 'wait').length < limits.max_waits
    return true
  }

  function add(list: FlowStep[], index: number, type: StepType): void {
    if (!canAdd(type)) {
      toasts.error('Llegaste al máximo de pasos de este tipo.')
      return
    }
    const allowed = (meta.value?.actions ?? []).filter((a) => a.triggers === null || a.triggers.includes(form.trigger))
    const step: FlowStep =
      type === 'wait' ? newWait(flow) : type === 'branch' ? newBranch(fields.value[0] ?? '', flow) : newAction(allowed[0]?.value ?? 'notify', flow)
    insertStep(list, index, step)
    selected.value = step.id
  }

  async function remove(id: string): Promise<void> {
    const location = locate(flow.steps, id)
    if (!location) return

    let keepYes = false
    if (location.step.type === 'branch') {
      const inside = countSteps(location.step.yes) + countSteps(location.step.no)
      if (inside > 0) {
        const answer = await confirm.askWithOption({
          title: 'Quitar la condición',
          message: `Sus caminos tienen ${inside} paso(s).`,
          confirmText: 'Quitar',
          danger: true,
          option: { label: 'Conservar los pasos de «Sí»', hint: 'Los de «No» se eliminan.', checked: true },
        })
        if (!answer.ok) return
        keepYes = answer.option
      }
    }

    removeStep(location, keepYes)
    if (selected.value === id) selected.value = null
  }

  function canMoveStep(id: string, direction: -1 | 1): boolean {
    const location = locate(flow.steps, id)
    return location !== null && canMove(location, direction)
  }

  function move(id: string, direction: -1 | 1): void {
    const location = locate(flow.steps, id)
    if (location) moveStep(location, direction)
  }

  function duplicate(id: string): void {
    const location = locate(flow.steps, id)
    if (!location || !canAdd(location.step.type)) return
    const copy = duplicateStep(flow, location)
    if (copy) selected.value = copy.id
  }

  function drop(list: FlowStep[], index: number): void {
    const id = dragging.value
    dragging.value = null
    if (!id) return
    if (!moveStepTo(flow, id, list, index)) {
      toasts.error('Una condición no puede ir dentro de sus propios caminos.')
    }
  }

  return {
    meta, form, flow, selected, errors, overlay, dragging, knownFields, fields,
    select, stepErrors, fieldError, canAdd, add, remove, move, canMoveStep, duplicate, drop,
  }
}

export function provideFlowEditor(editor: FlowEditor): void {
  provide(KEY, editor)
}

export function useFlowEditor(): FlowEditor {
  const editor = inject(KEY)
  if (!editor) throw new Error('useFlowEditor() fuera del editor de automatizaciones')
  return editor
}
