<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useConfirmStore } from '@/stores/confirm'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage } from '@/utils/errors'
import { date, dateLong, limit, money } from '@/utils/format'
import PageHeader from '@/components/ui/PageHeader.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import ModalDialog from '@/components/ui/ModalDialog.vue'
import StatusBadge, { type BadgeTone } from '@/components/ui/StatusBadge.vue'
import Spinner from '@/components/ui/Spinner.vue'
import AppIcon from '@/components/AppIcon.vue'

interface PlanPrice { interval: string; currency: string; amount_cents: number }
interface PlanDto {
  key: string
  name: string
  description: string | null
  prices: PlanPrice[]
  entitlements: Record<string, number | boolean>
}
interface Gateway { key: string; name: string; currency: string; is_offline: boolean }
interface SubscriptionDto {
  status: string
  status_label: string
  grants_access: boolean
  interval: string | null
  plan: string | null
  plan_name: string | null
  trial_ends_at: string | null
  current_period_end: string | null
  cancel_at_period_end: boolean
  gateway: string | null
  auto_renews: boolean
}
interface InvoiceDto {
  id: string
  number: string
  description: string | null
  amount_cents: number
  currency: string
  status: string
  gateway: string | null
  checkout_url: string | null
  instructions?: string | null
  issued_at: string | null
  paid_at: string | null
}
interface AddOnDto { name: string; quantity: number; entitlement: string; adds: number }

const auth = useAuthStore()
const toasts = useToastStore()
const confirmDialog = useConfirmStore()
const route = useRoute()
const router = useRouter()

const loading = ref(true)
const failed = ref(false)
const subscription = ref<SubscriptionDto | null>(null)
const entitlements = ref<Record<string, number | boolean>>({})
const usage = ref<Record<string, number>>({})
const pendingInvoice = ref<InvoiceDto | null>(null)
const addOns = ref<AddOnDto[]>([])
const plans = ref<PlanDto[]>([])
const gateways = ref<Gateway[]>([])
const invoices = ref<InvoiceDto[]>([])

const interval = ref<'month' | 'year'>('month')
const gatewayKey = ref('')
const selectedPlan = ref<PlanDto | null>(null)
const submitting = ref(false)
const pendingMessage = ref<string | null>(null)

const canChange = computed(() => auth.can('billing.change_plan'))
const canCancel = computed(() => auth.can('billing.cancel_subscription'))
const gateway = computed(() => gateways.value.find((g) => g.key === gatewayKey.value) ?? null)

const usageRows = [
  { key: 'brands.max', label: 'Marcas' },
  { key: 'social_accounts.max', label: 'Cuentas sociales' },
  { key: 'team_members.max', label: 'Miembros del equipo' },
  { key: 'scheduled_posts.month', label: 'Publicaciones este mes' },
  { key: 'ai_credits.month', label: 'Créditos de IA este mes' },
  { key: 'storage.gb', label: 'Almacenamiento (GB)' },
]

const planRows = [
  { key: 'brands.max', label: 'marcas' },
  { key: 'social_accounts.max', label: 'cuentas sociales' },
  { key: 'team_members.max', label: 'miembros del equipo' },
  { key: 'scheduled_posts.month', label: 'publicaciones/mes' },
  { key: 'ai_credits.month', label: 'créditos de IA/mes' },
  { key: 'storage.gb', label: 'GB de almacenamiento' },
]

const featureLabels: Record<string, string> = {
  'feature.approvals': 'Flujos de aprobación',
  'feature.inbox': 'Inbox unificado',
  'feature.analytics_advanced': 'Analítica avanzada y exportación',
  'feature.automations': 'Automatizaciones',
  'feature.byok': 'Claves de IA propias',
  'feature.api': 'API pública y MCP',
  'feature.white_label': 'Marca blanca',
}

function statusTone(status: string | undefined): BadgeTone {
  if (status === 'active') return 'success'
  if (status === 'trialing') return 'info'
  if (status === 'grace' || status === 'past_due') return 'warning'
  return 'danger'
}

function invoiceTone(status: string): BadgeTone {
  return status === 'paid' ? 'success' : status === 'open' ? 'warning' : 'neutral'
}

function priceOf(plan: PlanDto): PlanPrice | null {
  const currency = gateway.value?.currency ?? 'USD'
  return plan.prices.find((p) => p.interval === interval.value && p.currency === currency) ?? null
}

function usagePercent(key: string): number {
  const max = entitlements.value[key]
  const used = usage.value[key] ?? 0
  if (typeof max !== 'number' || max === -1) return 0
  if (max === 0) return used > 0 ? 100 : 0
  return Math.min(100, Math.round((used / max) * 100))
}

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const [sub, pl, gw, inv] = await Promise.all([
      http.get('/billing/subscription'),
      http.get('/billing/plans'),
      http.get('/billing/gateways'),
      http.get('/billing/invoices', { params: { per_page: 20 } }),
    ])
    subscription.value = sub.data.data.subscription
    entitlements.value = sub.data.data.entitlements
    usage.value = sub.data.data.usage
    pendingInvoice.value = sub.data.data.pending_invoice
    addOns.value = sub.data.data.add_ons
    plans.value = pl.data.data
    gateways.value = gw.data.data
    invoices.value = inv.data.data
    if (!gateways.value.some((g) => g.key === gatewayKey.value)) {
      gatewayKey.value = gateways.value.find((g) => !g.is_offline)?.key ?? gateways.value[0]?.key ?? ''
    }
    if (subscription.value?.interval === 'year') interval.value = 'year'
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

async function subscribe(): Promise<void> {
  if (!selectedPlan.value || !gatewayKey.value) return
  submitting.value = true
  try {
    const { data } = await http.post('/billing/subscribe', {
      plan: selectedPlan.value.key,
      interval: interval.value,
      gateway: gatewayKey.value,
    })
    if (data.data.status === 'redirect' && data.data.redirect_url) {
      window.location.href = data.data.redirect_url
      return
    }
    pendingMessage.value = data.data.message
    selectedPlan.value = null
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    submitting.value = false
  }
}

async function cancel(): Promise<void> {
  const ok = await confirmDialog.ask({
    title: 'Cancelar suscripción',
    message: 'Mantendrás el acceso hasta el final del periodo pagado y después no se renovará. Puedes reanudarla antes de esa fecha.',
    confirmText: 'Cancelar suscripción',
    cancelText: 'Mantener',
    danger: true,
  })
  if (!ok) return
  try {
    await http.post('/billing/cancel')
    toasts.success('Tu suscripción se cancelará al final del periodo.')
    await Promise.all([load(), auth.loadContext()])
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function resume(): Promise<void> {
  try {
    await http.post('/billing/resume')
    toasts.success('Tu suscripción seguirá activa.')
    await Promise.all([load(), auth.loadContext()])
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

function handleReturn(): void {
  const checkout = route.query.checkout as string | undefined
  if (!checkout) return
  if (checkout === 'success') {
    toasts.success('Pago recibido. Tu plan se activará en cuanto la pasarela lo confirme (normalmente en segundos).')
  } else if (checkout === 'cancelled') {
    toasts.error('El pago se canceló. Puedes intentarlo de nuevo cuando quieras.')
  }
  router.replace({ query: {} })
}

watch(() => route.query.checkout, handleReturn)
onMounted(() => {
  handleReturn()
  load()
})
</script>

<template>
  <div>
    <PageHeader title="Facturación" description="Tu plan, el uso de sus límites, los pagos y las facturas." />

    <div v-if="loading" class="space-y-4">
      <div class="card p-6"><div class="skeleton h-28 w-full" /></div>
      <div class="card p-6"><div class="skeleton h-40 w-full" /></div>
    </div>
    <ErrorState v-else-if="failed" @retry="load" />

    <template v-else>
      <!-- Aviso de pago pendiente -->
      <section
        v-if="pendingInvoice"
        class="card mb-6 border-amber-200 bg-amber-50/60 p-5 dark:border-amber-900/60 dark:bg-amber-950/20"
        aria-labelledby="pending-title"
      >
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 id="pending-title" class="flex items-center gap-2 font-semibold text-amber-900 dark:text-amber-200">
              <AppIcon name="alert" :size="18" /> Pago pendiente · {{ pendingInvoice.description }}
            </h2>
            <p class="mt-1 text-sm text-amber-800 dark:text-amber-300">
              {{ money(pendingInvoice.amount_cents, pendingInvoice.currency) }} · Factura {{ pendingInvoice.number }}.
              El nuevo plan se activa en cuanto se confirme el pago.
            </p>
            <p
              v-if="pendingInvoice.instructions || pendingMessage"
              class="mt-3 whitespace-pre-line rounded-lg bg-white/70 p-3 text-sm text-slate-700 dark:bg-slate-900/60 dark:text-slate-200"
            >
              {{ pendingInvoice.instructions || pendingMessage }}
            </p>
          </div>
          <a v-if="pendingInvoice.checkout_url" :href="pendingInvoice.checkout_url" class="btn-primary text-sm">
            Continuar el pago
          </a>
        </div>
      </section>

      <div class="mb-6 grid gap-6 lg:grid-cols-3">
        <!-- Suscripción actual -->
        <section class="card p-6 lg:col-span-1" aria-labelledby="plan-title">
          <p class="text-sm text-slate-500">Plan actual</p>
          <h2 id="plan-title" class="mt-1 text-2xl font-bold text-slate-900 dark:text-white">
            {{ subscription?.plan_name ?? 'Sin plan' }}
          </h2>
          <div class="mt-2 flex flex-wrap items-center gap-2">
            <StatusBadge :tone="statusTone(subscription?.status)" dot>{{ subscription?.status_label ?? 'Sin suscripción' }}</StatusBadge>
            <StatusBadge v-if="subscription?.interval" tone="neutral">
              {{ subscription.interval === 'year' ? 'Anual' : 'Mensual' }}
            </StatusBadge>
          </div>

          <dl class="mt-4 space-y-1.5 text-sm">
            <div v-if="subscription?.status === 'trialing'" class="flex justify-between gap-3">
              <dt class="text-slate-500">Prueba hasta</dt><dd class="font-medium">{{ dateLong(subscription.trial_ends_at) }}</dd>
            </div>
            <div v-else-if="subscription?.current_period_end" class="flex justify-between gap-3">
              <dt class="text-slate-500">{{ subscription.cancel_at_period_end ? 'Acceso hasta' : 'Periodo actual hasta' }}</dt>
              <dd class="font-medium">{{ dateLong(subscription.current_period_end) }}</dd>
            </div>
            <div v-if="subscription?.gateway" class="flex justify-between gap-3">
              <dt class="text-slate-500">Cobro</dt>
              <dd class="font-medium">{{ subscription.auto_renews ? 'Automático' : 'Manual cada periodo' }}</dd>
            </div>
          </dl>

          <p v-if="subscription && !subscription.grants_access" class="mt-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-700 dark:bg-rose-950/30 dark:text-rose-300">
            Tu suscripción no está activa: elige un plan para volver a publicar y usar todas las funciones.
          </p>
          <p v-else-if="subscription?.status === 'grace'" class="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-950/30 dark:text-amber-300">
            No recibimos el pago de la renovación. Mantienes el acceso unos días: renueva tu plan para evitar la suspensión.
          </p>

          <div class="mt-5 flex flex-wrap gap-2">
            <button
              v-if="subscription?.cancel_at_period_end && subscription.grants_access && canChange"
              type="button"
              class="btn-primary text-sm"
              @click="resume"
            >
              Reanudar suscripción
            </button>
            <button
              v-else-if="subscription?.grants_access && subscription.status !== 'trialing' && canCancel"
              type="button"
              class="btn-ghost text-sm text-rose-600"
              @click="cancel"
            >
              Cancelar suscripción
            </button>
          </div>
        </section>

        <!-- Uso -->
        <section class="card p-6 lg:col-span-2" aria-labelledby="usage-title">
          <h2 id="usage-title" class="font-semibold text-slate-900 dark:text-white">Uso del plan</h2>
          <ul class="mt-4 grid gap-4 sm:grid-cols-2">
            <li v-for="row in usageRows" :key="row.key">
              <div class="flex items-baseline justify-between gap-2 text-sm">
                <span class="text-slate-600 dark:text-slate-300">{{ row.label }}</span>
                <span class="font-medium text-slate-900 dark:text-white">
                  {{ usage[row.key] ?? 0 }} / {{ limit(entitlements[row.key]) }}
                </span>
              </div>
              <div
                class="mt-1.5 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800"
                role="progressbar"
                :aria-label="row.label"
                :aria-valuenow="usagePercent(row.key)"
                aria-valuemin="0"
                aria-valuemax="100"
              >
                <div
                  class="h-full rounded-full transition-all"
                  :class="usagePercent(row.key) >= 90 ? 'bg-rose-500' : usagePercent(row.key) >= 70 ? 'bg-amber-500' : 'bg-brand-500'"
                  :style="{ width: `${usagePercent(row.key)}%` }"
                />
              </div>
            </li>
          </ul>
          <p v-if="addOns.length" class="mt-4 text-xs text-slate-500">
            Incluye add-ons: {{ addOns.map((a) => `${a.name} × ${a.quantity}`).join(' · ') }}
          </p>
        </section>
      </div>

      <!-- Planes -->
      <section aria-labelledby="plans-title">
        <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
          <h2 id="plans-title" class="text-lg font-semibold text-slate-900 dark:text-white">Planes</h2>
          <div class="flex flex-wrap items-center gap-3">
            <div class="inline-flex rounded-lg border border-slate-200 bg-white p-0.5 dark:border-slate-700 dark:bg-slate-900" role="group" aria-label="Periodicidad">
              <button
                v-for="opt in [{ v: 'month', l: 'Mensual' }, { v: 'year', l: 'Anual' }] as const"
                :key="opt.v"
                type="button"
                class="rounded-md px-3 py-1.5 text-sm font-medium"
                :class="interval === opt.v ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800'"
                :aria-pressed="interval === opt.v"
                @click="interval = opt.v"
              >
                {{ opt.l }}
              </button>
            </div>
            <div v-if="gateways.length > 1">
              <label for="gw" class="sr-only">Forma de pago</label>
              <select id="gw" v-model="gatewayKey" class="input w-auto py-1.5">
                <option v-for="g in gateways" :key="g.key" :value="g.key">{{ g.name }} ({{ g.currency }})</option>
              </select>
            </div>
          </div>
        </div>

        <p v-if="gateways.length === 0" class="card mb-4 p-4 text-sm text-slate-500">
          No hay formas de pago habilitadas por el momento. Contacta con soporte para cambiar de plan.
        </p>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
          <article
            v-for="plan in plans"
            :key="plan.key"
            class="card flex flex-col p-6"
            :class="plan.key === subscription?.plan ? 'ring-2 ring-brand-500' : ''"
          >
            <div class="flex items-start justify-between gap-2">
              <h3 class="text-lg font-bold text-slate-900 dark:text-white">{{ plan.name }}</h3>
              <StatusBadge v-if="plan.key === subscription?.plan" tone="brand">Tu plan</StatusBadge>
            </div>
            <p class="mt-1 text-sm text-slate-500">{{ plan.description }}</p>
            <p class="mt-4 text-2xl font-bold text-slate-900 dark:text-white">
              <template v-if="priceOf(plan)">
                {{ money(priceOf(plan)!.amount_cents, priceOf(plan)!.currency) }}
                <span class="text-sm font-normal text-slate-400">/{{ interval === 'month' ? 'mes' : 'año' }}</span>
              </template>
              <span v-else class="text-sm font-normal text-slate-400">Sin precio en {{ gateway?.currency ?? 'esta moneda' }}</span>
            </p>
            <ul class="mt-4 flex-1 space-y-1.5 text-sm text-slate-600 dark:text-slate-300">
              <li v-for="row in planRows" :key="row.key" class="flex items-center gap-2">
                <AppIcon name="check" :size="16" class="shrink-0 text-emerald-500" />
                {{ limit(plan.entitlements[row.key]) }} {{ row.label }}
              </li>
              <li v-for="(label, key) in featureLabels" v-show="plan.entitlements[key] === true" :key="key" class="flex items-center gap-2">
                <AppIcon name="check" :size="16" class="shrink-0 text-emerald-500" /> {{ label }}
              </li>
            </ul>
            <button
              v-if="canChange"
              type="button"
              class="mt-5"
              :class="plan.key === subscription?.plan && subscription?.status === 'active' ? 'btn-secondary' : 'btn-primary'"
              :disabled="!priceOf(plan) || !gatewayKey || (plan.key === subscription?.plan && subscription?.status === 'active' && subscription?.interval === interval)"
              @click="selectedPlan = plan"
            >
              {{ plan.key === subscription?.plan && subscription?.status === 'active' ? (subscription?.interval === interval ? 'Plan actual' : 'Cambiar periodicidad') : 'Elegir plan' }}
            </button>
          </article>
        </div>
      </section>

      <!-- Facturas -->
      <section class="card mt-6 overflow-hidden" aria-labelledby="invoices-title">
        <h2 id="invoices-title" class="border-b border-slate-100 px-5 py-4 font-semibold text-slate-900 dark:border-slate-800 dark:text-white">
          Historial de facturas
        </h2>
        <p v-if="invoices.length === 0" class="p-5 text-sm text-slate-500">Aún no hay facturas.</p>
        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50">
              <tr>
                <th scope="col" class="px-5 py-2.5 font-medium">Factura</th>
                <th scope="col" class="px-5 py-2.5 font-medium">Fecha</th>
                <th scope="col" class="px-5 py-2.5 font-medium">Concepto</th>
                <th scope="col" class="px-5 py-2.5 text-right font-medium">Importe</th>
                <th scope="col" class="px-5 py-2.5 font-medium">Estado</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
              <tr v-for="inv in invoices" :key="inv.id">
                <td class="px-5 py-3 font-mono text-xs">{{ inv.number }}</td>
                <td class="px-5 py-3 text-slate-500">{{ date(inv.paid_at ?? inv.issued_at) }}</td>
                <td class="px-5 py-3">{{ inv.description }}</td>
                <td class="px-5 py-3 text-right font-medium">{{ money(inv.amount_cents, inv.currency) }}</td>
                <td class="px-5 py-3">
                  <StatusBadge :tone="invoiceTone(inv.status)">
                    {{ inv.status === 'paid' ? 'Pagada' : inv.status === 'open' ? 'Pendiente' : 'Anulada' }}
                  </StatusBadge>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>

    <ModalDialog
      :open="selectedPlan !== null"
      :title="`Cambiar al plan ${selectedPlan?.name ?? ''}`"
      description="Revisa el resumen antes de continuar al pago."
      size="sm"
      @close="selectedPlan = null"
    >
      <dl v-if="selectedPlan" class="space-y-2 text-sm">
        <div class="flex justify-between"><dt class="text-slate-500">Plan</dt><dd class="font-medium">{{ selectedPlan.name }}</dd></div>
        <div class="flex justify-between">
          <dt class="text-slate-500">Periodicidad</dt><dd class="font-medium">{{ interval === 'year' ? 'Anual' : 'Mensual' }}</dd>
        </div>
        <div class="flex justify-between">
          <dt class="text-slate-500">Importe</dt>
          <dd class="font-medium">{{ priceOf(selectedPlan) ? money(priceOf(selectedPlan)!.amount_cents, priceOf(selectedPlan)!.currency) : '—' }}</dd>
        </div>
        <div class="flex justify-between"><dt class="text-slate-500">Forma de pago</dt><dd class="font-medium">{{ gateway?.name }}</dd></div>
      </dl>
      <p class="mt-4 rounded-lg bg-slate-50 p-3 text-xs text-slate-500 dark:bg-slate-800/50">
        {{ gateway?.is_offline
          ? 'Recibirás las instrucciones de pago. El plan se activará cuando confirmemos el pago.'
          : 'Te llevaremos a la página segura de la pasarela. El plan se activa al confirmarse el pago.' }}
      </p>
      <template #footer>
        <button type="button" class="btn-secondary text-sm" @click="selectedPlan = null">Cancelar</button>
        <button type="button" class="btn-primary text-sm" :disabled="submitting" @click="subscribe">
          <Spinner v-if="submitting" :size="16" /> {{ gateway?.is_offline ? 'Solicitar plan' : 'Ir a pagar' }}
        </button>
      </template>
    </ModalDialog>
  </div>
</template>
