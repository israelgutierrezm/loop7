<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import http from '@/services/http'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage, apiValidationErrors } from '@/utils/errors'
import { hourLabel, WEEKDAYS } from '@/utils/reports'
import type { ClientReport, ReportFrequency, ReportPayload, ReportsIndex } from '@/types/reports'
import ModalDialog from '@/components/ui/ModalDialog.vue'
import Spinner from '@/components/ui/Spinner.vue'
import RecipientsInput from '@/components/reports/RecipientsInput.vue'

/**
 * Alta o edición de un informe: nombre, periodo, secciones, mensaje para el
 * cliente, destinatarios y envío automático.
 */
const props = defineProps<{ open: boolean; brandId: string; index: ReportsIndex; report?: ClientReport | null }>()
const emit = defineEmits<{ close: []; saved: [report: ClientReport] }>()

const MAX_RECIPIENTS = 10
const toasts = useToastStore()
const form = reactive<ReportPayload>({
  name: '',
  period: 'previous_month',
  sections: [],
  intro: null,
  recipients: [],
  frequency: null,
  send_day: null,
  send_hour: 8,
})
const errors = ref<Record<string, string[]>>({})
const saving = ref(false)
const title = computed(() => (props.report ? 'Editar informe' : 'Nuevo informe'))
const dayOptions = computed(() =>
  form.frequency === 'weekly'
    ? WEEKDAYS.map((label, i) => ({ value: i + 1, label: label[0]!.toUpperCase() + label.slice(1) }))
    : Array.from({ length: 28 }, (_, i) => ({ value: i + 1, label: `Día ${i + 1}` })),
)

watch(
  () => props.open,
  (open) => {
    if (!open) return
    const r = props.report
    form.name = r?.name ?? ''
    form.period = r?.period ?? 'previous_month'
    form.sections = r ? [...r.sections] : props.index.sections.filter((s) => s.available && !['best_times', 'competitors'].includes(s.value)).map((s) => s.value)
    form.intro = r?.intro ?? null
    form.recipients = r ? [...r.recipients] : []
    form.frequency = r?.frequency ?? null
    form.send_day = r?.send_day ?? null
    form.send_hour = r?.send_hour ?? 8
    errors.value = {}
  },
)

/** Al elegir la frecuencia, propone el día y el periodo que encajan. */
function setFrequency(value: ReportFrequency | null): void {
  form.frequency = value
  if (value === 'weekly') {
    form.send_day = form.send_day && form.send_day <= 7 ? form.send_day : 1
    if (!props.report) form.period = 'previous_week'
  } else if (value === 'monthly') {
    form.send_day = form.send_day ?? 1
    if (!props.report) form.period = 'previous_month'
  } else {
    form.send_day = null
  }
}

function toggleSection(value: string, checked: boolean): void {
  form.sections = checked ? [...form.sections, value] : form.sections.filter((s) => s !== value)
}

async function save(): Promise<void> {
  saving.value = true
  errors.value = {}
  try {
    const payload = { ...form, intro: form.intro?.trim() ? form.intro : null }
    const base = `/brands/${props.brandId}/reports`
    const { data } = props.report ? await http.patch(`${base}/${props.report.id}`, payload) : await http.post(base, payload)
    toasts.success(data.message ?? 'Informe guardado.')
    emit('saved', data.data)
    emit('close')
  } catch (e) {
    errors.value = apiValidationErrors(e)
    if (!Object.keys(errors.value).length) toasts.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

function firstError(key: string): string | undefined {
  return errors.value[key]?.[0] ?? Object.entries(errors.value).find(([k]) => k.startsWith(`${key}.`))?.[1]?.[0]
}
</script>

<template>
  <ModalDialog :open="open" :title="title" description="Un PDF con los resultados de la marca, listo para enviar a tu cliente." size="lg" @close="emit('close')">
    <form class="space-y-4" @submit.prevent="save">
      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label for="report-name" class="label">Nombre</label>
          <input id="report-name" v-model="form.name" class="input" maxlength="120" placeholder="Ej. Informe mensual" required :aria-invalid="!!errors.name" />
          <p v-if="errors.name" class="mt-1 text-xs text-rose-600">{{ errors.name[0] }}</p>
        </div>
        <div>
          <label for="report-period" class="label">Periodo</label>
          <select id="report-period" v-model="form.period" class="input">
            <option v-for="p in index.periods" :key="p.value" :value="p.value">{{ p.label }}</option>
          </select>
          <p class="mt-1 text-xs text-slate-500">Días completos en la zona horaria de la marca ({{ index.timezone }}).</p>
        </div>
      </div>

      <fieldset>
        <legend class="label">Secciones</legend>
        <div class="grid gap-2 sm:grid-cols-2">
          <label
            v-for="s in index.sections"
            :key="s.value"
            class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700"
            :class="!s.available ? 'opacity-60' : ''"
          >
            <input
              type="checkbox"
              class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
              :checked="form.sections.includes(s.value)"
              :disabled="!s.available"
              @change="toggleSection(s.value, ($event.target as HTMLInputElement).checked)"
            />
            <span>{{ s.label }}</span>
            <span v-if="!s.available" class="ml-auto text-xs text-slate-400">No incluido en tu plan</span>
          </label>
        </div>
        <p v-if="firstError('sections')" class="mt-1 text-xs text-rose-600">{{ firstError('sections') }}</p>
      </fieldset>

      <div>
        <label for="report-intro" class="label">Mensaje para el cliente <span class="text-slate-400">(opcional)</span></label>
        <textarea id="report-intro" v-model="form.intro" rows="3" maxlength="2000" class="input" placeholder="Ej. Este mes crecimos en Instagram gracias a la campaña de otoño…" />
        <p class="mt-1 text-xs text-slate-500">Aparece al principio del PDF y en el correo.</p>
        <p v-if="errors.intro" class="mt-1 text-xs text-rose-600">{{ errors.intro[0] }}</p>
      </div>

      <RecipientsInput v-model="form.recipients" :max="MAX_RECIPIENTS" :error="firstError('recipients')" />

      <fieldset class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
        <legend class="px-1 text-sm font-medium text-slate-700 dark:text-slate-200">Envío automático</legend>
        <div class="grid gap-3 sm:grid-cols-3">
          <div>
            <label for="report-frequency" class="label">Frecuencia</label>
            <select
              id="report-frequency"
              class="input"
              :value="form.frequency ?? ''"
              @change="setFrequency((($event.target as HTMLSelectElement).value || null) as ReportFrequency | null)"
            >
              <option value="">Sin envío automático</option>
              <option value="weekly">Semanal</option>
              <option value="monthly">Mensual</option>
            </select>
          </div>
          <div v-if="form.frequency">
            <label for="report-day" class="label">{{ form.frequency === 'weekly' ? 'Día de la semana' : 'Día del mes' }}</label>
            <select id="report-day" v-model.number="form.send_day" class="input">
              <option v-for="d in dayOptions" :key="d.value" :value="d.value">{{ d.label }}</option>
            </select>
            <p v-if="errors.send_day" class="mt-1 text-xs text-rose-600">{{ errors.send_day[0] }}</p>
          </div>
          <div v-if="form.frequency">
            <label for="report-hour" class="label">Hora</label>
            <select id="report-hour" v-model.number="form.send_hour" class="input">
              <option v-for="h in 24" :key="h" :value="h - 1">{{ hourLabel(h - 1) }}</option>
            </select>
          </div>
        </div>
        <p class="mt-2 text-xs text-slate-500">
          {{ form.frequency ? `Se envía a los destinatarios en la hora de la marca (${index.timezone}).` : 'Puedes generarlo o enviarlo cuando quieras desde la lista.' }}
        </p>
      </fieldset>

      <div class="flex justify-end gap-2 pt-1">
        <button type="button" class="btn-secondary text-sm" @click="emit('close')">Cancelar</button>
        <button type="submit" class="btn-primary text-sm" :disabled="saving">
          <Spinner v-if="saving" :size="16" /> Guardar
        </button>
      </div>
    </form>
  </ModalDialog>
</template>
