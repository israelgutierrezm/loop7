<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import http from '@/services/http'
import { dateTime, money } from '@/utils/format'
import PageHeader from '@/components/ui/PageHeader.vue'
import StatCard from '@/components/StatCard.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import AppIcon from '@/components/AppIcon.vue'

interface Stats {
  organizations: { total: number; active: number; suspended: number; new_last_30_days: number }
  users: { total: number; platform_admins: number; new_last_7_days: number }
  brands: { total: number }
  subscriptions: { by_status: Record<string, number>; by_plan: Record<string, number>; trials_ending_7_days: number }
  revenue: {
    mrr: Record<string, number>
    last_30_days: Record<string, number>
    conversions_30_days: number
    churn_30_days: number
    open_invoices: number
    failed_payments_30_days: number
  }
  publishing: { published_7_days: number; failed_7_days: number; scheduled: number }
  ai: { credits_this_month: number; generations_30_days: number }
  operations: { queued_jobs: number | null; failed_jobs: number; failed_webhooks: number; social_needing_attention: number }
  audit: { events_today: number }
  recent_organizations: { id: string; name: string; status: string; created_at: string | null }[]
}

const stats = ref<Stats | null>(null)
const loading = ref(true)
const failed = ref(false)

const statusLabels: Record<string, string> = {
  trialing: 'En prueba', active: 'Activas', grace: 'En gracia', past_due: 'Pago pendiente',
  suspended: 'Suspendidas', cancelled: 'Canceladas', expired: 'Expiradas',
}

function amounts(map: Record<string, number> | undefined): string {
  const entries = Object.entries(map ?? {})
  return entries.length ? entries.map(([currency, cents]) => money(cents, currency)).join(' · ') : money(0, 'USD')
}

const alerts = computed(() => {
  const s = stats.value
  if (!s) return []
  const list: { text: string; to: string }[] = []
  if (s.revenue.open_invoices) list.push({ text: `${s.revenue.open_invoices} pago(s) pendiente(s) de confirmar`, to: '/platform/payments' })
  if (s.operations.failed_webhooks) list.push({ text: `${s.operations.failed_webhooks} webhook(s) de pago fallido(s)`, to: '/platform/payments' })
  if (s.operations.failed_jobs) list.push({ text: `${s.operations.failed_jobs} trabajo(s) fallido(s) en colas`, to: '/platform/jobs' })
  if (s.subscriptions.trials_ending_7_days) list.push({ text: `${s.subscriptions.trials_ending_7_days} prueba(s) terminan en 7 días`, to: '/platform/subscriptions' })
  return list
})

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const { data } = await http.get('/platform/dashboard')
    stats.value = data.data
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader title="Salud de la plataforma" description="Negocio, clientes y operación de un vistazo.">
      <template #actions>
        <button type="button" class="btn-secondary text-sm" :disabled="loading" @click="load"><AppIcon name="refresh" :size="16" /> Actualizar</button>
      </template>
    </PageHeader>

    <div v-if="loading" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <div v-for="n in 8" :key="n" class="card p-5"><div class="skeleton h-14 w-full" /></div>
    </div>
    <ErrorState v-else-if="failed" @retry="load" />

    <template v-else-if="stats">
      <ul v-if="alerts.length" class="mb-6 space-y-2">
        <li v-for="a in alerts" :key="a.text">
          <RouterLink :to="a.to" class="flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-900 hover:bg-amber-100 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-200">
            <AppIcon name="alert" :size="16" /> {{ a.text }} <AppIcon name="chevron-right" :size="14" class="ml-auto" />
          </RouterLink>
        </li>
      </ul>

      <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Negocio</h2>
      <div class="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="MRR estimado" :value="amounts(stats.revenue.mrr)" icon="billing" hint="Suscripciones activas con cobro" />
        <StatCard label="Ingresos 30 días" :value="amounts(stats.revenue.last_30_days)" icon="analytics" hint="Facturas pagadas" />
        <StatCard label="Altas y cambios de plan" :value="stats.revenue.conversions_30_days" icon="sparkles" hint="Últimos 30 días" />
        <StatCard label="Bajas" :value="stats.revenue.churn_30_days" icon="alert" :hint="`${stats.revenue.failed_payments_30_days} cobros fallidos en 30 días`" />
      </div>

      <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Clientes</h2>
      <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="Organizaciones" :value="stats.organizations.total" icon="building" :hint="`+${stats.organizations.new_last_30_days} en 30 días · ${stats.organizations.suspended} suspendidas`" />
        <StatCard label="Usuarios" :value="stats.users.total" icon="team" :hint="`+${stats.users.new_last_7_days} en 7 días`" />
        <StatCard label="En prueba" :value="stats.subscriptions.by_status.trialing ?? 0" icon="refresh" :hint="`${stats.subscriptions.trials_ending_7_days} terminan en 7 días`" />
        <StatCard label="Marcas" :value="stats.brands.total" icon="brands" />
      </div>
      <div class="mb-8 grid gap-4 lg:grid-cols-3">
        <section class="card p-5" aria-labelledby="dash-status">
          <h3 id="dash-status" class="mb-3 font-semibold text-slate-900 dark:text-white">Suscripciones por estado</h3>
          <ul class="space-y-1.5 text-sm">
            <li v-for="(count, status) in stats.subscriptions.by_status" :key="status" class="flex justify-between">
              <span class="text-slate-600 dark:text-slate-300">{{ statusLabels[status] ?? status }}</span><span class="font-medium">{{ count }}</span>
            </li>
          </ul>
        </section>
        <section class="card p-5" aria-labelledby="dash-plans">
          <h3 id="dash-plans" class="mb-3 font-semibold text-slate-900 dark:text-white">Clientes por plan</h3>
          <p v-if="Object.keys(stats.subscriptions.by_plan).length === 0" class="text-sm text-slate-500">Sin suscripciones vigentes.</p>
          <ul v-else class="space-y-1.5 text-sm">
            <li v-for="(count, plan) in stats.subscriptions.by_plan" :key="plan" class="flex justify-between">
              <span class="text-slate-600 dark:text-slate-300">{{ plan }}</span><span class="font-medium">{{ count }}</span>
            </li>
          </ul>
        </section>
        <section class="card p-5" aria-labelledby="dash-recent">
          <h3 id="dash-recent" class="mb-3 font-semibold text-slate-900 dark:text-white">Organizaciones recientes</h3>
          <ul class="space-y-2 text-sm">
            <li v-for="o in stats.recent_organizations" :key="o.id" class="flex items-center justify-between gap-2">
              <RouterLink :to="`/platform/organizations/${o.id}`" class="truncate font-medium hover:text-brand-600">{{ o.name }}</RouterLink>
              <span class="shrink-0 text-xs text-slate-400">{{ dateTime(o.created_at) }}</span>
            </li>
          </ul>
        </section>
      </div>

      <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Operación</h2>
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="Publicaciones (7 días)" :value="stats.publishing.published_7_days" icon="social" :hint="`${stats.publishing.failed_7_days} fallidas · ${stats.publishing.scheduled} programadas`" />
        <StatCard label="Créditos de IA (mes)" :value="stats.ai.credits_this_month" icon="ai" :hint="`${stats.ai.generations_30_days} generaciones en 30 días`" />
        <StatCard
          label="Colas"
          :value="stats.operations.queued_jobs ?? '—'"
          icon="queue"
          :hint="`${stats.operations.failed_jobs} trabajos fallidos`"
        />
        <StatCard label="Eventos auditados hoy" :value="stats.audit.events_today" icon="shield" />
      </div>
      <p v-if="stats.operations.social_needing_attention" class="mt-4 text-sm text-slate-500">
        <StatusBadge tone="warning" dot>{{ stats.operations.social_needing_attention }}</StatusBadge>
        conexiones sociales de clientes necesitan reconectarse (token caducado o revocado).
      </p>
    </template>
  </div>
</template>
