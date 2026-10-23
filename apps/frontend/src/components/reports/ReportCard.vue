<script setup lang="ts">
import { computed, useId } from 'vue'
import { bytes, dateTime, relativeTime } from '@/utils/format'
import { formatNumber } from '@/utils/numbers'
import { periodRange, scheduleLabel } from '@/utils/reports'
import type { ClientReport, ReportRun } from '@/types/reports'
import AppIcon from '@/components/AppIcon.vue'
import StatusBadge, { type BadgeTone } from '@/components/ui/StatusBadge.vue'

/**
 * Un informe: su configuración, sus acciones y los últimos generados (con el
 * enlace al PDF cuando está listo).
 */
const props = defineProps<{ report: ClientReport; canManage: boolean; sectionLabels: Record<string, string> }>()
const emit = defineEmits<{ edit: []; remove: []; generate: []; send: [] }>()

const titleId = useId()
const schedule = computed(() => scheduleLabel(props.report))
const TRIGGERS: Record<ReportRun['trigger'], string> = { manual: 'PDF', send: 'Envío', scheduled: 'Programado' }

function status(run: ReportRun): { tone: BadgeTone; label: string } {
  if (run.status === 'pending') return { tone: 'info', label: run.trigger === 'manual' ? 'Generando…' : 'Generando y enviando…' }
  if (run.status === 'failed') return { tone: 'danger', label: 'Falló' }
  if (run.trigger === 'manual') return { tone: 'success', label: 'Listo' }
  if (run.sent_at) return { tone: 'success', label: `Enviado a ${run.delivered}` }
  return run.error ? { tone: 'warning', label: 'Envío incompleto' } : { tone: 'info', label: 'Enviando…' }
}
</script>

<template>
  <article class="card p-5" :aria-labelledby="titleId">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-0">
        <h3 :id="titleId" class="font-semibold text-slate-900 dark:text-white">{{ report.name }}</h3>
        <p class="mt-0.5 text-sm text-slate-500">
          {{ report.period_label }} · {{ schedule }}
          <template v-if="report.next_send_at"> · próximo envío {{ relativeTime(report.next_send_at) }}</template>
        </p>
      </div>
      <div class="flex flex-wrap gap-2">
        <template v-if="canManage">
          <button type="button" class="btn-secondary text-sm" @click="emit('generate')">
            <AppIcon name="document" :size="16" /> Generar PDF
          </button>
          <button
            type="button"
            class="btn-secondary text-sm"
            :disabled="report.recipients.length === 0"
            :title="report.recipients.length === 0 ? 'Añade destinatarios para enviarlo' : ''"
            @click="emit('send')"
          >
            <AppIcon name="bolt" :size="16" /> Enviar ahora
          </button>
          <button type="button" class="btn-ghost p-2" :aria-label="`Editar ${report.name}`" @click="emit('edit')">
            <AppIcon name="settings" :size="16" />
          </button>
          <button type="button" class="btn-ghost p-2 text-rose-600" :aria-label="`Eliminar ${report.name}`" @click="emit('remove')">
            <AppIcon name="trash" :size="16" />
          </button>
        </template>
      </div>
    </div>

    <div class="mt-3 flex flex-wrap gap-1.5">
      <span v-for="s in report.sections" :key="s" class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">
        {{ sectionLabels[s] ?? s }}
      </span>
    </div>
    <p class="mt-2 text-xs text-slate-500">
      <template v-if="report.recipients.length">Para: {{ report.recipients.join(', ') }}</template>
      <template v-else>Sin destinatarios: sólo descarga.</template>
      <template v-if="report.last_sent_at"> · último envío {{ relativeTime(report.last_sent_at) }}</template>
    </p>

    <div v-if="report.runs.length" class="mt-4 overflow-x-auto">
      <table class="w-full text-left text-sm" :aria-label="`Informes generados de ${report.name}`">
        <thead>
          <tr class="border-b border-slate-200 text-xs uppercase text-slate-500 dark:border-slate-700">
            <th scope="col" class="py-2 pr-3 font-medium">Periodo</th>
            <th scope="col" class="py-2 pr-3 font-medium">Tipo</th>
            <th scope="col" class="py-2 pr-3 font-medium">Estado</th>
            <th scope="col" class="py-2 pr-3 font-medium">Seguidores</th>
            <th scope="col" class="py-2 pr-3 font-medium">Generado</th>
            <th scope="col" class="py-2 font-medium"><span class="sr-only">PDF</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="run in report.runs" :key="run.id" class="border-b border-slate-100 last:border-0 dark:border-slate-800">
            <td class="py-2 pr-3 whitespace-nowrap">{{ periodRange(run.period_from, run.period_to) }}</td>
            <td class="py-2 pr-3 text-slate-500">{{ TRIGGERS[run.trigger] }}</td>
            <td class="py-2 pr-3">
              <StatusBadge :tone="status(run).tone" dot>{{ status(run).label }}</StatusBadge>
              <p v-if="run.error" class="mt-1 text-xs text-rose-600">{{ run.error }}</p>
            </td>
            <td class="py-2 pr-3 text-slate-600 dark:text-slate-300">{{ formatNumber(run.summary?.followers) }}</td>
            <td class="py-2 pr-3 whitespace-nowrap text-slate-500" :title="dateTime(run.created_at)">{{ relativeTime(run.created_at) }}</td>
            <td class="py-2 text-right whitespace-nowrap">
              <a
                v-if="run.download_url"
                :href="run.download_url"
                target="_blank"
                rel="noopener"
                class="inline-flex items-center gap-1 text-sm font-medium text-brand-600 hover:underline"
                :aria-label="`Abrir el PDF del ${periodRange(run.period_from, run.period_to)}`"
              >
                <AppIcon name="document" :size="14" /> PDF<span v-if="run.file_size" class="text-xs text-slate-400"> · {{ bytes(run.file_size) }}</span>
              </a>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <p v-else class="mt-4 text-sm text-slate-500">Aún no se ha generado. {{ canManage ? 'Pulsa «Generar PDF» para ver cómo queda.' : '' }}</p>
  </article>
</template>
