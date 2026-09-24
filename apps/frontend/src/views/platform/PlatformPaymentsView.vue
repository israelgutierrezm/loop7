<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import http from '@/services/http'
import { useConfirmStore } from '@/stores/confirm'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage } from '@/utils/errors'
import { dateTime, money } from '@/utils/format'
import PageHeader from '@/components/ui/PageHeader.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import StatusBadge, { type BadgeTone } from '@/components/ui/StatusBadge.vue'

type Tab = 'invoices' | 'transactions' | 'webhooks'
interface Invoice { id: string; number: string; organization: string | null; description: string | null; amount_cents: number; currency: string; status: string; gateway: string | null; issued_at: string | null; paid_at: string | null }
interface Transaction { id: string; organization: string | null; gateway: string; environment: string; provider_transaction_id: string | null; amount_cents: number; currency: string; status: string; created_at: string | null }
interface WebhookEvent { id: number; gateway: string; environment: string; provider_event_id: string; event_type: string | null; status: string; result: string | null; error: string | null; verification_code: string | null; received_at: string | null }

const toasts = useToastStore()
const confirmDialog = useConfirmStore()

const tab = ref<Tab>('invoices')
const status = ref('')
const rows = ref<(Invoice | Transaction | WebhookEvent)[]>([])
const loading = ref(true)
const failed = ref(false)
const page = ref(1)
const lastPage = ref(1)

const tabs: { key: Tab; label: string }[] = [
  { key: 'invoices', label: 'Facturas' },
  { key: 'transactions', label: 'Transacciones' },
  { key: 'webhooks', label: 'Webhooks' },
]

const statusOptions: Record<Tab, { value: string; label: string }[]> = {
  invoices: [
    { value: '', label: 'Todas' },
    { value: 'open', label: 'Pendientes' },
    { value: 'paid', label: 'Pagadas' },
    { value: 'void', label: 'Anuladas' },
  ],
  transactions: [
    { value: '', label: 'Todas' },
    { value: 'succeeded', label: 'Cobradas' },
    { value: 'failed', label: 'Fallidas' },
  ],
  webhooks: [
    { value: '', label: 'Todos' },
    { value: 'processed', label: 'Procesados' },
    { value: 'ignored', label: 'Sin efecto' },
    { value: 'failed', label: 'Fallidos' },
    { value: 'received', label: 'En cola' },
  ],
}

const endpoints: Record<Tab, string> = {
  invoices: '/platform/invoices',
  transactions: '/platform/transactions',
  webhooks: '/platform/webhook-events',
}

function tone(value: string): BadgeTone {
  return (
    { paid: 'success', succeeded: 'success', processed: 'success', open: 'warning', received: 'info', failed: 'danger' } as Record<string, BadgeTone>
  )[value] ?? 'neutral'
}

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const { data } = await http.get(endpoints[tab.value], { params: { status: status.value || undefined, page: page.value } })
    rows.value = data.data
    lastPage.value = data.meta.last_page ?? 1
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

async function markPaid(invoice: Invoice): Promise<void> {
  const ok = await confirmDialog.ask({
    title: `Confirmar pago de ${invoice.number}`,
    message: `${invoice.organization ?? ''} · ${money(invoice.amount_cents, invoice.currency)}. Se activará o renovará su plan.`,
    confirmText: 'Confirmar pago',
  })
  if (!ok) return
  try {
    const { data } = await http.post(`/platform/invoices/${invoice.id}/mark-paid`)
    toasts.success(data.message ?? 'Pago confirmado.')
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function voidInvoice(invoice: Invoice): Promise<void> {
  const ok = await confirmDialog.ask({ title: `Anular ${invoice.number}`, message: 'La solicitud de pago quedará sin efecto.', confirmText: 'Anular', danger: true })
  if (!ok) return
  try {
    await http.post(`/platform/invoices/${invoice.id}/void`)
    toasts.success('Factura anulada.')
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function retry(event: WebhookEvent): Promise<void> {
  try {
    await http.post(`/platform/webhook-events/${event.id}/retry`)
    toasts.success('Reintento en marcha.')
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

watch(tab, () => {
  status.value = ''
  page.value = 1
  load()
})
watch(status, () => {
  page.value = 1
  load()
})
onMounted(load)
</script>

<template>
  <div>
    <PageHeader title="Pagos y facturas" description="Cobros de todas las organizaciones, confirmación de pagos manuales y registro de webhooks de las pasarelas." />

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <div class="inline-flex rounded-lg border border-slate-200 bg-white p-0.5 dark:border-slate-700 dark:bg-slate-900" role="tablist">
        <button
          v-for="t in tabs"
          :key="t.key"
          type="button"
          role="tab"
          :aria-selected="tab === t.key"
          class="rounded-md px-3 py-1.5 text-sm font-medium"
          :class="tab === t.key ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800'"
          @click="tab = t.key"
        >
          {{ t.label }}
        </button>
      </div>
      <label for="pay-status" class="sr-only">Estado</label>
      <select id="pay-status" v-model="status" class="input w-auto">
        <option v-for="o in statusOptions[tab]" :key="o.value" :value="o.value">{{ o.label }}</option>
      </select>
    </div>

    <div v-if="loading" class="card p-6"><div v-for="n in 6" :key="n" class="skeleton my-2 h-8 w-full" /></div>
    <ErrorState v-else-if="failed" @retry="load" />
    <p v-else-if="rows.length === 0" class="card p-6 text-sm text-slate-500">No hay registros con ese filtro.</p>

    <div v-else class="card overflow-hidden">
      <div class="overflow-x-auto">
        <!-- Facturas -->
        <table v-if="tab === 'invoices'" class="w-full text-left text-sm">
          <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50">
            <tr>
              <th scope="col" class="px-5 py-3 font-medium">Factura</th>
              <th scope="col" class="px-5 py-3 font-medium">Organización</th>
              <th scope="col" class="px-5 py-3 font-medium">Concepto</th>
              <th scope="col" class="px-5 py-3 font-medium">Pasarela</th>
              <th scope="col" class="px-5 py-3 text-right font-medium">Importe</th>
              <th scope="col" class="px-5 py-3 font-medium">Estado</th>
              <th scope="col" class="px-5 py-3"><span class="sr-only">Acciones</span></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
            <tr v-for="inv in rows as Invoice[]" :key="inv.id">
              <td class="px-5 py-3">
                <p class="font-mono text-xs">{{ inv.number }}</p>
                <p class="text-xs text-slate-400">{{ dateTime(inv.paid_at ?? inv.issued_at) }}</p>
              </td>
              <td class="px-5 py-3">{{ inv.organization }}</td>
              <td class="px-5 py-3 text-slate-600 dark:text-slate-300">{{ inv.description }}</td>
              <td class="px-5 py-3 text-slate-500">{{ inv.gateway ?? '—' }}</td>
              <td class="px-5 py-3 text-right font-medium">{{ money(inv.amount_cents, inv.currency) }}</td>
              <td class="px-5 py-3">
                <StatusBadge :tone="tone(inv.status)">{{ inv.status === 'paid' ? 'Pagada' : inv.status === 'open' ? 'Pendiente' : 'Anulada' }}</StatusBadge>
              </td>
              <td class="whitespace-nowrap px-5 py-3 text-right">
                <template v-if="inv.status === 'open'">
                  <button type="button" class="btn-ghost px-2 py-1 text-xs" @click="markPaid(inv)">Confirmar pago</button>
                  <button type="button" class="btn-ghost px-2 py-1 text-xs text-rose-600" @click="voidInvoice(inv)">Anular</button>
                </template>
              </td>
            </tr>
          </tbody>
        </table>

        <!-- Transacciones -->
        <table v-else-if="tab === 'transactions'" class="w-full text-left text-sm">
          <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50">
            <tr>
              <th scope="col" class="px-5 py-3 font-medium">Fecha</th>
              <th scope="col" class="px-5 py-3 font-medium">Organización</th>
              <th scope="col" class="px-5 py-3 font-medium">Pasarela</th>
              <th scope="col" class="px-5 py-3 font-medium">Referencia</th>
              <th scope="col" class="px-5 py-3 text-right font-medium">Importe</th>
              <th scope="col" class="px-5 py-3 font-medium">Estado</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
            <tr v-for="t in rows as Transaction[]" :key="t.id">
              <td class="px-5 py-3 text-slate-500">{{ dateTime(t.created_at) }}</td>
              <td class="px-5 py-3">{{ t.organization ?? '—' }}</td>
              <td class="px-5 py-3">{{ t.gateway }} <span class="text-xs text-slate-400">({{ t.environment }})</span></td>
              <td class="px-5 py-3 font-mono text-xs text-slate-500">{{ t.provider_transaction_id ?? '—' }}</td>
              <td class="px-5 py-3 text-right font-medium">{{ money(t.amount_cents, t.currency) }}</td>
              <td class="px-5 py-3"><StatusBadge :tone="tone(t.status)">{{ t.status === 'succeeded' ? 'Cobrada' : 'Fallida' }}</StatusBadge></td>
            </tr>
          </tbody>
        </table>

        <!-- Webhooks -->
        <table v-else class="w-full text-left text-sm">
          <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50">
            <tr>
              <th scope="col" class="px-5 py-3 font-medium">Recibido</th>
              <th scope="col" class="px-5 py-3 font-medium">Pasarela</th>
              <th scope="col" class="px-5 py-3 font-medium">Evento</th>
              <th scope="col" class="px-5 py-3 font-medium">Estado</th>
              <th scope="col" class="px-5 py-3 font-medium">Resultado</th>
              <th scope="col" class="px-5 py-3"><span class="sr-only">Acciones</span></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
            <tr v-for="w in rows as WebhookEvent[]" :key="w.id">
              <td class="px-5 py-3 text-slate-500">{{ dateTime(w.received_at) }}</td>
              <td class="px-5 py-3">{{ w.gateway }} <span class="text-xs text-slate-400">({{ w.environment }})</span></td>
              <td class="px-5 py-3">
                <p>{{ w.event_type ?? '—' }}</p>
                <p class="font-mono text-[11px] text-slate-400">{{ w.provider_event_id }}</p>
              </td>
              <td class="px-5 py-3"><StatusBadge :tone="tone(w.status)">{{ w.status }}</StatusBadge></td>
              <td class="max-w-md px-5 py-3 text-xs text-slate-600 dark:text-slate-300">
                <p v-if="w.verification_code" class="font-medium text-brand-700 dark:text-brand-300">
                  Código de verificación de Openpay: <span class="font-mono">{{ w.verification_code }}</span>
                </p>
                <p v-if="w.error" class="text-rose-600">{{ w.error }}</p>
                <p v-else>{{ w.result ?? '—' }}</p>
              </td>
              <td class="px-5 py-3 text-right">
                <button v-if="w.status === 'failed'" type="button" class="btn-ghost px-2 py-1 text-xs" @click="retry(w)">Reintentar</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="lastPage > 1" class="flex items-center justify-end gap-2 border-t border-slate-100 px-5 py-3 text-sm dark:border-slate-800">
        <button type="button" class="btn-ghost px-3 py-1 text-xs" :disabled="page <= 1" @click="page--; load()">Anterior</button>
        <span class="text-slate-500">Página {{ page }} de {{ lastPage }}</span>
        <button type="button" class="btn-ghost px-3 py-1 text-xs" :disabled="page >= lastPage" @click="page++; load()">Siguiente</button>
      </div>
    </div>
  </div>
</template>
