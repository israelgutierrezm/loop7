<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useConfirmStore } from '@/stores/confirm'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage } from '@/utils/errors'
import type { ClientReport, ReportRun, ReportsIndex } from '@/types/reports'
import PageHeader from '@/components/ui/PageHeader.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import BrandPicker from '@/components/BrandPicker.vue'
import AppIcon from '@/components/AppIcon.vue'
import ReportCard from '@/components/reports/ReportCard.vue'
import ReportDialog from '@/components/reports/ReportDialog.vue'
import GenerateDialog from '@/components/reports/GenerateDialog.vue'

/**
 * Informes para clientes (docs/05): PDF con los resultados de una marca, con la
 * marca blanca de la agencia, para descargar o enviar por correo (también de
 * forma programada).
 */
const auth = useAuthStore()
const toasts = useToastStore()
const confirmDialog = useConfirmStore()

const POLL_MS = 4000
const MAX_POLLS = 60
const brandId = ref<string | null>(auth.brands[0]?.id ?? null)
const index = ref<ReportsIndex | null>(null)
const loading = ref(true)
const failed = ref(false)
const editing = ref<ClientReport | null>(null)
const dialog = ref(false)
const generating = ref<{ report: ClientReport; send: boolean } | null>(null)
let pollTimer: number | undefined
let polls = 0

const canManage = computed(() => auth.can('analytics.reports'))
const usage = computed(() => index.value?.usage ?? { used: 0, limit: 0 })
const included = computed(() => usage.value.limit !== 0)
const full = computed(() => usage.value.limit !== -1 && usage.value.used >= usage.value.limit)
const sectionLabels = computed(() => Object.fromEntries((index.value?.sections ?? []).map((s) => [s.value, s.label])))
const pending = computed(() => (index.value?.reports ?? []).some((r) => r.runs.some((run) => run.status === 'pending' || (run.trigger !== 'manual' && run.status === 'ready' && !run.sent_at && !run.error))))

async function load(silent = false): Promise<void> {
  if (!brandId.value) {
    loading.value = false
    return
  }
  if (!silent) {
    loading.value = true
    failed.value = false
  }
  try {
    const { data } = await http.get(`/brands/${brandId.value}/reports`)
    index.value = data.data
  } catch (e) {
    if (!silent) failed.value = true
    console.warn(apiErrorMessage(e))
  } finally {
    loading.value = false
  }
  schedulePoll()
}

/** Mientras haya informes generándose, se consulta cada pocos segundos. */
function schedulePoll(): void {
  window.clearTimeout(pollTimer)
  if (!pending.value || polls >= MAX_POLLS) {
    polls = 0
    return
  }
  polls++
  pollTimer = window.setTimeout(() => void load(true), POLL_MS)
}

function openCreate(): void {
  editing.value = null
  dialog.value = true
}

function openEdit(report: ClientReport): void {
  editing.value = report
  dialog.value = true
}

function onStarted(run: ReportRun): void {
  const report = index.value?.reports.find((r) => r.id === generating.value?.report.id)
  if (report) report.runs = [run, ...report.runs.filter((r) => r.id !== run.id)].slice(0, 5)
  polls = 0
  schedulePoll()
}

async function remove(report: ClientReport): Promise<void> {
  const ok = await confirmDialog.ask({
    title: `Eliminar «${report.name}»`,
    message: 'Se borran también sus PDF generados y deja de enviarse.',
    confirmText: 'Eliminar',
    danger: true,
  })
  if (!ok) return
  try {
    await http.delete(`/brands/${brandId.value}/reports/${report.id}`)
    toasts.success('Informe eliminado.')
    await load(true)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

watch(brandId, () => void load())
onMounted(() => void load())
onBeforeUnmount(() => window.clearTimeout(pollTimer))
</script>

<template>
  <div class="space-y-4">
    <PageHeader title="Informes" description="Informes en PDF con los resultados de cada marca, listos para enviar a tus clientes.">
      <template #actions>
        <BrandPicker v-model="brandId" />
        <button v-if="canManage && included && index" type="button" class="btn-primary text-sm" :disabled="full || loading" :title="full ? 'Llegaste al límite de tu plan' : ''" @click="openCreate">
          <AppIcon name="plus" :size="16" /> Nuevo informe
        </button>
      </template>
    </PageHeader>

    <EmptyState v-if="!brandId" title="Primero crea una marca" description="Los informes se hacen por marca." />
    <div v-else-if="loading" class="space-y-3">
      <div class="skeleton h-32 w-full" />
      <div class="skeleton h-32 w-full" />
    </div>
    <ErrorState v-else-if="failed" @retry="load()" />
    <template v-else-if="index">
      <div v-if="!included" class="card flex flex-wrap items-center justify-between gap-3 p-5 text-sm">
        <span class="flex items-center gap-2 text-slate-600 dark:text-slate-300">
          <AppIcon name="sparkles" :size="16" /> Los informes para clientes están disponibles desde el plan Growth.
        </span>
        <RouterLink v-if="auth.can('billing.view')" to="/app/billing" class="btn-secondary text-sm">Ver planes</RouterLink>
      </div>

      <template v-else>
        <p class="text-sm text-slate-500">
          {{ usage.limit === -1 ? `${usage.used} informes (sin límite en tu plan)` : `Usas ${usage.used} de ${usage.limit} informes de tu plan` }}.
          El PDF lleva {{ auth.hasFeature('feature.white_label') ? 'la marca blanca de tu organización' : 'la marca de la plataforma' }}.
        </p>
        <EmptyState
          v-if="index.reports.length === 0"
          title="Aún no hay informes para esta marca"
          :description="canManage ? 'Crea uno: elige las secciones, el periodo y a quién enviarlo.' : 'Quien gestiona la analítica puede crearlos.'"
        >
          <template v-if="canManage" #action>
            <button type="button" class="btn-primary text-sm" :disabled="full" @click="openCreate"><AppIcon name="plus" :size="16" /> Crear informe</button>
          </template>
        </EmptyState>
        <div v-else class="space-y-3">
          <ReportCard
            v-for="report in index.reports"
            :key="report.id"
            :report="report"
            :can-manage="canManage"
            :section-labels="sectionLabels"
            @edit="openEdit(report)"
            @remove="remove(report)"
            @generate="generating = { report, send: false }"
            @send="generating = { report, send: true }"
          />
        </div>
      </template>
    </template>

    <ReportDialog
      v-if="index && brandId"
      :open="dialog"
      :brand-id="brandId"
      :index="index"
      :report="editing"
      @close="dialog = false"
      @saved="load(true)"
    />
    <GenerateDialog
      v-if="index && brandId"
      :open="generating !== null"
      :brand-id="brandId"
      :index="index"
      :report="generating?.report ?? null"
      :send="generating?.send ?? false"
      @close="generating = null"
      @started="onStarted"
    />
  </div>
</template>
