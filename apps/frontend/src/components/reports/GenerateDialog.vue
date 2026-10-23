<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import http from '@/services/http'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage, apiValidationErrors } from '@/utils/errors'
import type { ClientReport, ReportRun, ReportsIndex } from '@/types/reports'
import ModalDialog from '@/components/ui/ModalDialog.vue'
import Spinner from '@/components/ui/Spinner.vue'

/**
 * Generar el PDF (o enviarlo ya a los destinatarios) con el periodo del informe,
 * otro predefinido o unas fechas concretas.
 */
const props = defineProps<{ open: boolean; brandId: string; index: ReportsIndex; report: ClientReport | null; send: boolean }>()
const emit = defineEmits<{ close: []; started: [run: ReportRun] }>()

const toasts = useToastStore()
const period = ref('')
const from = ref('')
const to = ref('')
const errors = ref<Record<string, string[]>>({})
const busy = ref(false)
const title = computed(() => (props.send ? 'Enviar informe ahora' : 'Generar PDF'))
const today = new Date().toISOString().slice(0, 10)

watch(
  () => props.open,
  (open) => {
    if (!open || !props.report) return
    period.value = props.report.period
    from.value = ''
    to.value = ''
    errors.value = {}
  },
)

async function submit(): Promise<void> {
  if (!props.report) return
  busy.value = true
  errors.value = {}
  try {
    const body = period.value === 'custom' ? { period: 'custom', from: from.value, to: to.value } : { period: period.value }
    const { data } = await http.post(`/brands/${props.brandId}/reports/${props.report.id}/${props.send ? 'send' : 'runs'}`, body)
    toasts.success(data.message ?? 'En marcha.')
    emit('started', data.data)
    emit('close')
  } catch (e) {
    errors.value = apiValidationErrors(e)
    const first = Object.values(errors.value)[0]?.[0]
    toasts.error(first ?? apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <ModalDialog :open="open" :title="title" :description="report?.name ?? ''" size="md" @close="emit('close')">
    <form class="space-y-4" @submit.prevent="submit">
      <div>
        <label for="generate-period" class="label">Periodo</label>
        <select id="generate-period" v-model="period" class="input">
          <option v-for="p in index.periods" :key="p.value" :value="p.value">{{ p.label }}{{ p.value === report?.period ? ' (el del informe)' : '' }}</option>
          <option value="custom">Fechas concretas…</option>
        </select>
      </div>
      <div v-if="period === 'custom'" class="grid gap-3 sm:grid-cols-2">
        <div>
          <label for="generate-from" class="label">Desde</label>
          <input id="generate-from" v-model="from" type="date" class="input" :max="today" required />
          <p v-if="errors.from" class="mt-1 text-xs text-rose-600">{{ errors.from[0] }}</p>
        </div>
        <div>
          <label for="generate-to" class="label">Hasta</label>
          <input id="generate-to" v-model="to" type="date" class="input" :max="today" required />
          <p v-if="errors.to" class="mt-1 text-xs text-rose-600">{{ errors.to[0] }}</p>
        </div>
      </div>
      <p v-if="send && report" class="rounded-lg bg-slate-50 p-3 text-sm text-slate-600 dark:bg-slate-800/60 dark:text-slate-300">
        Se enviará a {{ report.recipients.length }} {{ report.recipients.length === 1 ? 'destinatario' : 'destinatarios' }}: {{ report.recipients.join(', ') }}.
      </p>
      <div class="flex justify-end gap-2 pt-1">
        <button type="button" class="btn-secondary text-sm" @click="emit('close')">Cancelar</button>
        <button type="submit" class="btn-primary text-sm" :disabled="busy">
          <Spinner v-if="busy" :size="16" /> {{ send ? 'Enviar' : 'Generar' }}
        </button>
      </div>
    </form>
  </ModalDialog>
</template>
