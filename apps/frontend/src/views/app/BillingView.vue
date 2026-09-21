<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage } from '@/utils/errors'
import PageHeader from '@/components/ui/PageHeader.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import ModalDialog from '@/components/ui/ModalDialog.vue'
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
interface SubscriptionDto {
  status: string
  status_label: string
  interval: string | null
  plan: string | null
  plan_name: string | null
  trial_ends_at: string | null
  current_period_end: string | null
  cancel_at_period_end: boolean
}

const auth = useAuthStore()
const toasts = useToastStore()

const loading = ref(true)
const failed = ref(false)
const subscription = ref<SubscriptionDto | null>(null)
const entitlements = ref<Record<string, number | boolean>>({})
const usage = ref<Record<string, number>>({})
const plans = ref<PlanDto[]>([])
const gateways = ref<{ key: string; name: string }[]>([])

const showSubscribe = ref(false)
const selectedPlan = ref<PlanDto | null>(null)
const interval = ref<'month' | 'year'>('month')
const gateway = ref('')
const submitting = ref(false)

const canChange = computed(() => auth.can('billing.change_plan'))

function money(plan: PlanDto): string {
  const price = plan.prices.find((p) => p.interval === interval.value) ?? plan.prices[0]
  if (!price) return '—'
  return new Intl.NumberFormat('es', { style: 'currency', currency: price.currency }).format(price.amount_cents / 100)
}

function formatDate(iso: string | null): string {
  return iso ? new Date(iso).toLocaleDateString('es', { dateStyle: 'long' }) : '—'
}

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const [sub, pl, gw] = await Promise.all([
      http.get('/billing/subscription'),
      http.get('/billing/plans'),
      http.get('/billing/gateways'),
    ])
    subscription.value = sub.data.data.subscription
    entitlements.value = sub.data.data.entitlements
    usage.value = sub.data.data.usage
    plans.value = pl.data.data
    gateways.value = gw.data.data
    gateway.value = gateways.value[0]?.key ?? ''
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

function openSubscribe(plan: PlanDto): void {
  selectedPlan.value = plan
  showSubscribe.value = true
}

async function subscribe(): Promise<void> {
  if (!selectedPlan.value) return
  submitting.value = true
  try {
    await http.post('/billing/subscribe', {
      plan: selectedPlan.value.key,
      interval: interval.value,
      gateway: gateway.value,
    })
    showSubscribe.value = false
    toasts.success('Plan actualizado.')
    await load()
    await auth.loadContext()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    submitting.value = false
  }
}

async function cancel(): Promise<void> {
  if (!confirm('¿Cancelar la suscripción al final del periodo?')) return
  try {
    await http.post('/billing/cancel')
    toasts.success('Suscripción marcada para cancelación.')
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

function limitLabel(key: string): string {
  const v = entitlements.value[key]
  if (v === -1) return 'Ilimitado'
  return String(v ?? 0)
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader title="Facturación" description="Gestiona tu plan y tu suscripción." />

    <div v-if="loading" class="card p-6"><div class="skeleton h-40 w-full" /></div>
    <ErrorState v-else-if="failed" @retry="load" />

    <template v-else>
      <!-- Suscripción actual -->
      <div class="card mb-6 p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div>
            <p class="text-sm text-slate-500">Plan actual</p>
            <p class="text-2xl font-bold text-slate-900 dark:text-white">
              {{ subscription?.plan_name ?? 'Sin plan' }}
            </p>
            <span
              class="mt-2 inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold"
              :class="subscription?.status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'"
            >
              {{ subscription?.status_label ?? '—' }}
            </span>
          </div>
          <div class="text-right text-sm text-slate-500">
            <p v-if="subscription?.status === 'trialing'">
              Prueba hasta {{ formatDate(subscription?.trial_ends_at ?? null) }}
            </p>
            <p v-else-if="subscription?.current_period_end">
              Renueva el {{ formatDate(subscription?.current_period_end ?? null) }}
            </p>
            <p v-if="subscription?.cancel_at_period_end" class="mt-1 font-medium text-rose-600">
              Se cancelará al final del periodo
            </p>
          </div>
        </div>

        <!-- Uso -->
        <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div class="rounded-lg border border-slate-100 p-4 dark:border-slate-800">
            <p class="text-sm text-slate-500">Marcas</p>
            <p class="text-lg font-semibold">{{ usage['brands.max'] ?? 0 }} / {{ limitLabel('brands.max') }}</p>
          </div>
          <div class="rounded-lg border border-slate-100 p-4 dark:border-slate-800">
            <p class="text-sm text-slate-500">Miembros</p>
            <p class="text-lg font-semibold">{{ usage['team_members.max'] ?? 0 }} / {{ limitLabel('team_members.max') }}</p>
          </div>
        </div>

        <div v-if="canChange && subscription && !subscription.cancel_at_period_end" class="mt-4">
          <button class="btn-ghost text-sm text-rose-600" @click="cancel">Cancelar suscripción</button>
        </div>
      </div>

      <!-- Planes -->
      <h2 class="mb-3 text-lg font-semibold text-slate-900 dark:text-white">Planes disponibles</h2>
      <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        <div
          v-for="plan in plans"
          :key="plan.key"
          class="card flex flex-col p-6"
          :class="plan.key === subscription?.plan ? 'ring-2 ring-brand-500' : ''"
        >
          <p class="text-lg font-bold text-slate-900 dark:text-white">{{ plan.name }}</p>
          <p class="mt-1 text-sm text-slate-500">{{ plan.description }}</p>
          <p class="mt-4 text-2xl font-bold text-slate-900 dark:text-white">
            {{ money(plan) }}<span class="text-sm font-normal text-slate-400">/{{ interval === 'month' ? 'mes' : 'año' }}</span>
          </p>
          <ul class="mt-4 flex-1 space-y-1 text-sm text-slate-600 dark:text-slate-300">
            <li class="flex items-center gap-2">
              <AppIcon name="check" :size="16" class="text-emerald-500" />
              {{ plan.entitlements['brands.max'] === -1 ? 'Marcas ilimitadas' : plan.entitlements['brands.max'] + ' marcas' }}
            </li>
            <li class="flex items-center gap-2">
              <AppIcon name="check" :size="16" class="text-emerald-500" />
              {{ plan.entitlements['team_members.max'] === -1 ? 'Miembros ilimitados' : plan.entitlements['team_members.max'] + ' miembros' }}
            </li>
            <li v-if="plan.entitlements['feature.inbox']" class="flex items-center gap-2">
              <AppIcon name="check" :size="16" class="text-emerald-500" /> Inbox
            </li>
            <li v-if="plan.entitlements['feature.automations']" class="flex items-center gap-2">
              <AppIcon name="check" :size="16" class="text-emerald-500" /> Automatizaciones
            </li>
          </ul>
          <button
            v-if="canChange"
            class="mt-5"
            :class="plan.key === subscription?.plan ? 'btn-secondary' : 'btn-primary'"
            :disabled="plan.key === subscription?.plan"
            @click="openSubscribe(plan)"
          >
            {{ plan.key === subscription?.plan ? 'Plan actual' : 'Seleccionar' }}
          </button>
        </div>
      </div>
    </template>

    <ModalDialog :open="showSubscribe" :title="`Cambiar a ${selectedPlan?.name ?? ''}`" @close="showSubscribe = false">
      <div class="space-y-4">
        <div>
          <span class="label">Periodicidad</span>
          <div class="flex gap-2">
            <button
              class="flex-1"
              :class="interval === 'month' ? 'btn-primary' : 'btn-secondary'"
              @click="interval = 'month'"
            >
              Mensual
            </button>
            <button
              class="flex-1"
              :class="interval === 'year' ? 'btn-primary' : 'btn-secondary'"
              @click="interval = 'year'"
            >
              Anual
            </button>
          </div>
        </div>
        <div>
          <label class="label" for="gw">Pasarela de pago</label>
          <select id="gw" v-model="gateway" class="input">
            <option v-for="g in gateways" :key="g.key" :value="g.key">{{ g.name }}</option>
          </select>
          <p v-if="gateways.length === 0" class="mt-1 text-xs text-rose-600">
            No hay pasarelas de pago habilitadas.
          </p>
        </div>
        <div class="flex justify-end gap-2 pt-2">
          <button class="btn-secondary" @click="showSubscribe = false">Cancelar</button>
          <button class="btn-primary" :disabled="submitting || !gateway" @click="subscribe">
            <Spinner v-if="submitting" :size="18" /> Confirmar
          </button>
        </div>
      </div>
    </ModalDialog>
  </div>
</template>
