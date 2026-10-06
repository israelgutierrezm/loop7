<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import http from '@/services/http'
import { useToastStore } from '@/stores/toasts'
import { useFlowEditor } from '@/composables/useFlowEditor'
import { apiErrorMessage, apiValidationErrors } from '@/utils/errors'
import type { TraceEntry } from '@/types/automations'
import ModalDialog from '@/components/ui/ModalDialog.vue'
import Spinner from '@/components/ui/Spinner.vue'
import AppIcon from '@/components/AppIcon.vue'

/**
 * «Probar»: datos de ejemplo del disparador → por qué camino iría el flujo y qué
 * haría cada acción. No ejecuta nada (ni avisos, ni webhooks, ni borradores).
 */
const props = defineProps<{ open: boolean }>()
const emit = defineEmits<{ close: [] }>()

const editor = useFlowEditor()
const toasts = useToastStore()

const rows = ref<{ field: string; value: string }[]>([])
const running = ref(false)

const free = computed(() => editor.form.trigger === 'webhook.received')

// Al abrir: los campos del disparador con valores de ejemplo.
watch(
  () => props.open,
  (open) => {
    if (!open) return
    const examples = editor.meta.value?.triggers.find((t) => t.value === editor.form.trigger)?.examples ?? {}
    const previous = new Map(rows.value.map((r) => [r.field, r.value]))
    const fields = [...new Set([...Object.keys(examples), ...editor.fields.value])]
    rows.value = fields.map((field) => ({ field, value: previous.get(field) ?? examples[field] ?? '' }))
    if (rows.value.length === 0) rows.value.push({ field: '', value: '' })
  },
)

async function run(): Promise<void> {
  running.value = true
  try {
    const context = Object.fromEntries(rows.value.filter((r) => r.field.trim()).map((r) => [r.field.trim(), r.value]))
    const { data } = await http.post('/automations/simulate', {
      trigger: editor.form.trigger,
      brand: editor.form.brand || null,
      flow: editor.flow,
      context,
    })
    const steps = data.data.steps as TraceEntry[]
    editor.errors.value = {}
    editor.overlay.value = {
      kind: 'test',
      label: 'Prueba con datos de ejemplo (no se ejecutó nada)',
      entries: Object.fromEntries(steps.map((s) => [s.id, s])),
    }
    const actions = steps.filter((s) => s.type === 'action').length
    toasts.success(actions > 0 ? `Se harían ${actions} acción(es): míralo en el diagrama.` : 'Con estos datos no se haría ninguna acción.')
    emit('close')
  } catch (e) {
    const errors = apiValidationErrors(e)
    if (Object.keys(errors).length) {
      editor.errors.value = errors
      toasts.error('Revisa los pasos marcados en el diagrama.')
      emit('close')
    } else {
      toasts.error(apiErrorMessage(e))
    }
  } finally {
    running.value = false
  }
}
</script>

<template>
  <ModalDialog :open="open" title="Probar la automatización" description="Elige datos de ejemplo y verás en el diagrama por dónde iría y qué haría. No se envía nada." size="lg" @close="emit('close')">
    <form class="space-y-3" @submit.prevent="run">
      <div class="max-h-[50vh] space-y-2 overflow-y-auto pr-1">
        <div v-for="(r, i) in rows" :key="i" class="grid grid-cols-1 gap-2 sm:grid-cols-[180px_1fr_auto] sm:items-center">
          <input
            v-if="free"
            v-model="r.field"
            class="input font-mono text-xs"
            placeholder="campo"
            :aria-label="`Campo ${i + 1}`"
          />
          <label v-else :for="`test-${i}`" class="truncate font-mono text-xs text-slate-600 dark:text-slate-300">{{ r.field }}</label>
          <input :id="`test-${i}`" v-model="r.value" class="input" :aria-label="free ? `Valor ${i + 1}` : undefined" />
          <button v-if="free" type="button" class="btn-ghost p-1 text-rose-600" :aria-label="`Quitar campo ${i + 1}`" @click="rows.splice(i, 1)">
            <AppIcon name="close" :size="14" />
          </button>
        </div>
      </div>
      <button v-if="free" type="button" class="btn-ghost text-xs" @click="rows.push({ field: '', value: '' })">
        <AppIcon name="plus" :size="14" /> Añadir campo
      </button>
      <div class="flex justify-end gap-2 pt-2">
        <button type="button" class="btn-secondary text-sm" @click="emit('close')">Cancelar</button>
        <button type="submit" class="btn-primary text-sm" :disabled="running">
          <Spinner v-if="running" :size="16" /> <AppIcon v-else name="play" :size="14" /> Probar
        </button>
      </div>
    </form>
  </ModalDialog>
</template>
