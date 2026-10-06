<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { onBeforeRouteLeave, onBeforeRouteUpdate, useRoute, useRouter } from 'vue-router'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useConfirmStore } from '@/stores/confirm'
import { useToastStore } from '@/stores/toasts'
import { createFlowEditor, provideFlowEditor } from '@/composables/useFlowEditor'
import { apiErrorMessage, apiValidationErrors } from '@/utils/errors'
import { countSteps } from '@/utils/automationFlow'
import { findTemplate } from '@/utils/automationTemplates'
import type { Automation, AutomationRun } from '@/types/automations'
import ErrorState from '@/components/ui/ErrorState.vue'
import Spinner from '@/components/ui/Spinner.vue'
import AppIcon from '@/components/AppIcon.vue'
import FlowCanvas from '@/components/automations/FlowCanvas.vue'
import RunHistory from '@/components/automations/RunHistory.vue'
import StepInspector from '@/components/automations/StepInspector.vue'
import TestFlowDialog from '@/components/automations/TestFlowDialog.vue'

/**
 * Editor visual de una automatización (docs/05): diagrama del flujo a la
 * izquierda y panel del paso seleccionado a la derecha. `/app/automations/nueva`
 * crea una (opcionalmente desde una plantilla: `?plantilla=`).
 */
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toasts = useToastStore()
const confirm = useConfirmStore()

const editor = createFlowEditor()
provideFlowEditor(editor)

const loading = ref(true)
const failed = ref(false)
const saving = ref(false)
const testing = ref(false)
const current = ref<Automation | null>(null)
const runs = ref<AutomationRun[]>([])
const runsLoading = ref(false)
const activeRun = ref<string | null>(null)
const snapshot = ref('')
const nameInput = ref<HTMLInputElement | null>(null)

const isNew = computed(() => route.params.automation === 'nueva')
const canSave = computed(() => auth.can(current.value ? 'automations.update' : 'automations.create'))
const nameError = computed(() => editor.errors.value.name?.[0])
const flowError = computed(() => editor.errors.value.flow?.[0])
const stepCount = computed(() => countSteps(editor.flow.steps))

function serialize(): string {
  const { id: _id, ...form } = editor.form
  return JSON.stringify({ form, flow: editor.flow })
}
const dirty = computed(() => !loading.value && !failed.value && serialize() !== snapshot.value)

function apply(automation: Automation): void {
  current.value = automation
  Object.assign(editor.form, {
    id: automation.id,
    name: automation.name,
    trigger: automation.trigger,
    feed_url: automation.trigger_config?.feed_url ?? '',
    brand: automation.brand ?? '',
    is_enabled: automation.is_enabled,
  })
  editor.flow.steps = JSON.parse(JSON.stringify(automation.flow.steps ?? []))
}

function startFromTemplate(): void {
  const template = findTemplate(typeof route.query.plantilla === 'string' ? route.query.plantilla : null)
  current.value = null
  runs.value = []
  editor.knownFields.value = []
  Object.assign(editor.form, {
    id: '',
    name: template.key === 'en-blanco' ? '' : template.name,
    trigger: template.trigger,
    feed_url: '',
    brand: template.needsBrand ? auth.brands[0]?.id ?? '' : '',
    is_enabled: true,
  })
  editor.flow.steps = JSON.parse(JSON.stringify(template.flow.steps))
  // Lo que hay que completar primero.
  const needsTrigger = template.trigger === 'rss.item_published' || template.needsBrand
  editor.select(needsTrigger ? 'trigger' : editor.flow.steps[0]?.id ?? 'trigger')
}

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  editor.errors.value = {}
  editor.overlay.value = null
  activeRun.value = null
  try {
    const [metaRes, itemRes] = await Promise.all([
      editor.meta.value ? Promise.resolve(null) : http.get('/automations/meta'),
      isNew.value ? Promise.resolve(null) : http.get(`/automations/${route.params.automation}`),
    ])
    if (metaRes) editor.meta.value = metaRes.data.data
    if (itemRes) {
      apply(itemRes.data.data)
      runs.value = itemRes.data.data.runs ?? []
      editor.knownFields.value = itemRes.data.data.fields ?? []
      editor.select(null)
    } else {
      startFromTemplate()
    }
    snapshot.value = serialize()
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

async function reloadRuns(): Promise<void> {
  if (!current.value) return
  runsLoading.value = true
  try {
    const { data } = await http.get(`/automations/${current.value.id}`)
    runs.value = data.data.runs ?? []
    editor.knownFields.value = data.data.fields ?? []
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    runsLoading.value = false
  }
}

async function save(): Promise<void> {
  if (!editor.form.name.trim()) {
    editor.errors.value = { name: ['Ponle un nombre a la automatización.'] }
    nameInput.value?.focus()
    return
  }
  saving.value = true
  try {
    const payload = {
      name: editor.form.name.trim(),
      trigger: editor.form.trigger,
      trigger_config: editor.form.trigger === 'rss.item_published' ? { feed_url: editor.form.feed_url.trim() } : null,
      brand: editor.form.brand || null,
      is_enabled: editor.form.is_enabled,
      flow: editor.flow,
    }
    const { data } = current.value
      ? await http.put(`/automations/${current.value.id}`, payload)
      : await http.post('/automations', payload)
    const saved = data.data as Automation
    const created = !current.value
    editor.errors.value = {}
    apply(saved)
    snapshot.value = serialize()

    if (created) {
      await router.replace({ name: 'automation-edit', params: { automation: saved.id } })
      if (saved.inbound_url) editor.select('trigger')
      toasts.success(saved.inbound_url ? 'Automatización creada: copia su URL secreta.' : 'Automatización creada.')
    } else {
      toasts.success('Automatización guardada.')
    }
  } catch (e) {
    editor.errors.value = apiValidationErrors(e)
    const keys = Object.keys(editor.errors.value)
    toasts.error(keys.length ? 'Revisa los pasos marcados en el diagrama.' : apiErrorMessage(e))
    // Abre el primer paso con error.
    const step = keys.find((k) => k.startsWith('flow.'))?.split('.')[1]
    if (step) editor.select(step)
    else if (keys.some((k) => k.startsWith('trigger_config'))) editor.select('trigger')
  } finally {
    saving.value = false
  }
}

function showRun(run: AutomationRun | null): void {
  activeRun.value = run?.id ?? null
  editor.overlay.value = run
    ? {
        kind: 'run',
        label: `Ejecución del ${new Date(run.created_at ?? '').toLocaleString('es', { dateStyle: 'short', timeStyle: 'short' })}`,
        entries: Object.fromEntries(run.steps.map((s) => [s.id, s])),
      }
    : null
}

function clearOverlay(): void {
  editor.overlay.value = null
  activeRun.value = null
}

function onUpdated(automation: Automation): void {
  // Sólo cambian datos que no se editan (p. ej. la URL renovada).
  current.value = automation
}

// En pantallas estrechas el panel va debajo del diagrama: se lleva a la vista.
watch(editor.selected, async (id) => {
  if (!id || window.matchMedia('(min-width: 1024px)').matches) return
  await nextTick()
  document.getElementById('inspector-title')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
})

async function confirmLeave(): Promise<boolean> {
  if (!dirty.value) return true
  return confirm.ask({
    title: 'Salir sin guardar',
    message: 'Tienes cambios sin guardar en esta automatización.',
    confirmText: 'Salir sin guardar',
    danger: true,
  })
}

onBeforeRouteLeave(confirmLeave)
onBeforeRouteUpdate(async (to) => {
  // Al guardar una nueva sólo cambia la URL: no se recarga.
  if (current.value && to.params.automation === current.value.id) return true
  if (!(await confirmLeave())) return false
  await nextTick()
  return true
})
watch(
  () => route.params.automation,
  (id) => {
    if (typeof id === 'string' && id !== current.value?.id) load()
  },
)

function onBeforeUnload(event: BeforeUnloadEvent): void {
  if (!dirty.value) return
  event.preventDefault()
  event.returnValue = ''
}

onMounted(() => {
  load()
  window.addEventListener('beforeunload', onBeforeUnload)
})
onBeforeUnmount(() => window.removeEventListener('beforeunload', onBeforeUnload))
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center gap-3">
      <RouterLink to="/app/automations" class="btn-ghost text-sm">
        <AppIcon name="chevron-left" :size="16" /> Automatizaciones
      </RouterLink>
      <div class="min-w-0 flex-1">
        <label for="automation-name" class="sr-only">Nombre de la automatización</label>
        <input
          id="automation-name"
          ref="nameInput"
          v-model="editor.form.name"
          class="w-full rounded-lg border border-transparent bg-transparent px-2 py-1 text-xl font-semibold text-slate-900 placeholder:text-slate-400 hover:border-slate-200 focus:border-slate-300 focus:outline-none focus:ring-2 focus:ring-brand-500 dark:text-white dark:hover:border-slate-700"
          placeholder="Nombre de la automatización"
          maxlength="120"
          :disabled="loading || failed"
          :aria-invalid="!!nameError"
          aria-describedby="automation-name-error"
        />
        <p v-if="nameError" id="automation-name-error" class="px-2 text-xs text-rose-600">{{ nameError }}</p>
      </div>
      <label class="flex cursor-pointer items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
        <input v-model="editor.form.is_enabled" type="checkbox" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500" :disabled="loading || failed" />
        Activa
      </label>
      <span v-if="!loading && !canSave" class="text-xs text-slate-500">Solo lectura: no tienes permiso para guardar.</span>
      <span v-else-if="dirty" class="text-xs font-medium text-amber-700 dark:text-amber-400" role="status">Cambios sin guardar</span>
      <button type="button" class="btn-secondary text-sm" :disabled="loading || failed" @click="testing = true">
        <AppIcon name="play" :size="14" /> Probar
      </button>
      <button type="button" class="btn-primary text-sm" :disabled="saving || loading || failed || !canSave" @click="save">
        <Spinner v-if="saving" :size="16" /> Guardar
      </button>
    </div>

    <div v-if="loading" class="card p-6"><div class="skeleton h-64 w-full" /></div>
    <ErrorState v-else-if="failed" @retry="load" />

    <template v-else>
      <p v-if="flowError" class="flex items-center gap-2 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:bg-rose-950/40 dark:text-rose-300" role="alert">
        <AppIcon name="alert" :size="16" /> {{ flowError }}
      </p>

      <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_360px]">
        <div class="card min-w-0">
          <div
            v-if="editor.overlay.value"
            class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-2 text-xs dark:border-slate-800"
            role="status"
          >
            <span class="flex items-center gap-1.5 text-slate-600 dark:text-slate-300">
              <AppIcon :name="editor.overlay.value.kind === 'test' ? 'play' : 'clock'" :size="14" />
              {{ editor.overlay.value.label }}
            </span>
            <button type="button" class="btn-ghost px-2 py-1 text-xs" @click="clearOverlay">Quitar del diagrama</button>
          </div>
          <FlowCanvas />
        </div>

        <aside class="space-y-4 lg:sticky lg:top-20 lg:self-start">
          <StepInspector v-if="editor.selected.value" :current="current" @updated="onUpdated" />
          <section v-else class="card space-y-2 p-4 text-sm text-slate-600 dark:text-slate-300" aria-label="Cómo usar el editor">
            <p class="font-semibold text-slate-900 dark:text-white">Cómo funciona</p>
            <ul class="list-disc space-y-1 pl-4 text-xs">
              <li>Haz clic en un paso para editarlo; el primero es el <strong>disparador</strong>.</li>
              <li>Con <strong>+</strong> añades una acción, una condición (con caminos «Sí» y «No») o una espera.</li>
              <li>Arrastra un paso hasta otro hueco para moverlo.</li>
              <li><strong>Probar</strong> recorre el flujo con datos de ejemplo sin ejecutar nada.</li>
            </ul>
            <p class="text-xs text-slate-400">{{ stepCount }} de {{ editor.meta.value?.limits.max_steps ?? 30 }} pasos.</p>
          </section>

          <div v-if="current" class="card space-y-2 p-4">
            <RunHistory :runs="runs" :loading="runsLoading" :active-id="activeRun" @show="showRun" />
            <button type="button" class="btn-ghost px-2 py-1 text-xs" :disabled="runsLoading" @click="reloadRuns">
              <AppIcon name="refresh" :size="14" /> Actualizar
            </button>
          </div>
        </aside>
      </div>
    </template>

    <TestFlowDialog :open="testing" @close="testing = false" />
  </div>
</template>
