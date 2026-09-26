<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useConfirmStore } from '@/stores/confirm'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage, apiValidationErrors } from '@/utils/errors'
import { date, dateLong, limit, money, relativeTime } from '@/utils/format'
import PageHeader from '@/components/ui/PageHeader.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import ModalDialog from '@/components/ui/ModalDialog.vue'
import Spinner from '@/components/ui/Spinner.vue'
import StatusBadge, { type BadgeTone } from '@/components/ui/StatusBadge.vue'
import AppIcon from '@/components/AppIcon.vue'

interface OrgMember {
  id: string; name: string; email: string; role: string | null; status: string
  is_owner: boolean; blocked: boolean; is_platform_admin: boolean; last_login_at: string | null
}
interface OrgBrand { id: string; name: string; connections_count: number; created_at: string | null }
interface OrgSummary {
  id: string; name: string; slug: string; status: string; billing_email: string | null
  members_count: number | null; brands_count: number | null; created_at: string | null
  members: OrgMember[]; brands: OrgBrand[]
}
interface Billing {
  subscription: {
    plan: string | null; plan_name: string | null; status: string; status_label: string; interval: string | null
    gateway: string | null; trial_ends_at: string | null; current_period_end: string | null; cancel_at_period_end: boolean
  } | null
  entitlements: Record<string, number | boolean>
  usage: Record<string, number>
  overrides: { key: string; value: number | boolean; note: string | null }[]
  add_ons: { key: string; name: string; quantity: number }[]
  invoices: { id: string; number: string; description: string | null; amount_cents: number; currency: string; status: string; gateway: string | null; issued_at: string | null; paid_at: string | null }[]
}
interface EntitlementDef { key: string; type: 'limit' | 'bool'; label: string }

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toasts = useToastStore()
const confirmDialog = useConfirmStore()
const orgId = route.params.organization as string

const loading = ref(true)
const failed = ref(false)
const org = ref<OrgSummary | null>(null)
const billing = ref<Billing | null>(null)
const plans = ref<{ key: string; name: string }[]>([])
const addOnCatalog = ref<{ key: string; name: string; entitlement_key: string; quantity_per_unit: number; is_active: boolean }[]>([])
const definitions = ref<EntitlementDef[]>([])
const busy = ref(false)

const planForm = reactive({ plan: '', interval: 'month' })
const trialDays = ref(14)
const overrides = ref<{ key: string; value: number | boolean; unlimited: boolean; note: string }[]>([])
const addOnQty = reactive<Record<string, number>>({})

const definitionOf = (key: string): EntitlementDef | undefined => definitions.value.find((d) => d.key === key)
const availableOverrideKeys = computed(() => definitions.value.filter((d) => !overrides.value.some((o) => o.key === d.key)))

function subTone(status: string | undefined): BadgeTone {
  return ({ active: 'success', trialing: 'info', grace: 'warning' } as Record<string, BadgeTone>)[status ?? ''] ?? 'danger'
}

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const [o, b, p, a, d] = await Promise.all([
      http.get(`/platform/organizations/${orgId}`),
      http.get(`/platform/organizations/${orgId}/billing`),
      http.get('/platform/plans'),
      http.get('/platform/add-ons'),
      http.get('/platform/entitlements'),
    ])
    org.value = o.data.data
    billing.value = b.data.data
    plans.value = p.data.data
    addOnCatalog.value = a.data.data
    definitions.value = d.data.data
    planForm.plan = billing.value?.subscription?.plan ?? plans.value[0]?.key ?? ''
    planForm.interval = billing.value?.subscription?.interval ?? 'month'
    overrides.value = (billing.value?.overrides ?? []).map((ov) => ({
      key: ov.key,
      value: ov.value,
      unlimited: ov.value === -1,
      note: ov.note ?? '',
    }))
    for (const item of addOnCatalog.value) {
      addOnQty[item.key] = billing.value?.add_ons.find((x) => x.key === item.key)?.quantity ?? 0
    }
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

async function run(action: () => Promise<{ data: { message?: string | null } }>, success?: string): Promise<void> {
  busy.value = true
  try {
    const { data } = await action()
    toasts.success(success ?? data.message ?? 'Hecho.')
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

// --- Suspensión (con motivo para la auditoría) ---
const suspendOpen = ref(false)
const suspendReason = ref('')

async function toggleStatus(): Promise<void> {
  if (!org.value) return
  if (org.value.status !== 'suspended') {
    suspendReason.value = ''
    suspendOpen.value = true
    return
  }
  await run(() => http.post(`/platform/organizations/${orgId}/activate`))
}

async function confirmSuspend(): Promise<void> {
  suspendOpen.value = false
  await run(() => http.post(`/platform/organizations/${orgId}/suspend`, { reason: suspendReason.value.trim() || null }))
}

// --- Soporte: entrar como un miembro ---
async function impersonate(member: OrgMember): Promise<void> {
  const ok = await confirmDialog.ask({
    title: `Entrar como ${member.name}`,
    message: 'Verás la aplicación como esta persona durante un máximo de 60 minutos. Las acciones sensibles están bloqueadas y todo queda auditado.',
    confirmText: 'Impersonar',
  })
  if (!ok) return
  try {
    await http.post(`/platform/impersonate/${member.id}`)
    await auth.fetchMe()
    toasts.success(`Estás viendo la aplicación como ${member.name}.`)
    router.push({ path: '/app', query: { org: orgId } })
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

function canImpersonate(member: OrgMember): boolean {
  return member.status === 'active' && !member.blocked && !member.is_platform_admin
}

// --- Eliminación a petición del cliente ---
const deleteOpen = ref(false)
const deleteName = ref('')
const deleteErrors = ref<Record<string, string[]>>({})
const deleting = ref(false)

async function deleteOrganization(): Promise<void> {
  deleting.value = true
  deleteErrors.value = {}
  try {
    await http.delete(`/platform/organizations/${orgId}`, { data: { confirm_name: deleteName.value } })
    toasts.success('Organización eliminada.')
    router.push('/platform/organizations')
  } catch (e) {
    deleteErrors.value = apiValidationErrors(e)
    if (!Object.keys(deleteErrors.value).length) toasts.error(apiErrorMessage(e))
  } finally {
    deleting.value = false
  }
}

async function changePlan(): Promise<void> {
  const ok = await confirmDialog.ask({
    title: 'Activar plan sin cobro',
    message: 'Se activará el plan de inmediato como cortesía o acuerdo comercial (no genera cobro en ninguna pasarela).',
    confirmText: 'Activar plan',
  })
  if (ok) await run(() => http.post(`/platform/organizations/${orgId}/billing/plan`, planForm))
}

async function extendTrial(): Promise<void> {
  await run(() => http.post(`/platform/organizations/${orgId}/billing/extend-trial`, { days: trialDays.value }))
}

async function cancel(immediately: boolean): Promise<void> {
  const ok = await confirmDialog.ask({
    title: immediately ? 'Cancelar de inmediato' : 'Cancelar al final del periodo',
    message: immediately ? 'La organización pierde el acceso a las funciones del plan ahora mismo.' : 'Mantendrá el acceso hasta que termine el periodo actual.',
    confirmText: 'Cancelar suscripción',
    danger: true,
  })
  if (ok) await run(() => http.post(`/platform/organizations/${orgId}/billing/cancel`, { immediately }))
}

function addOverride(key: string): void {
  const def = definitionOf(key)
  if (!def) return
  overrides.value.push({ key, value: def.type === 'bool' ? true : 0, unlimited: false, note: '' })
}

async function saveOverrides(): Promise<void> {
  const payload = overrides.value.map((o) => {
    const isBool = definitionOf(o.key)?.type === 'bool'
    return {
      key: o.key,
      value: isBool ? Boolean(o.value) : o.unlimited ? -1 : Math.max(0, Math.floor(Number(o.value))),
      note: o.note || null,
    }
  })
  await run(() => http.put(`/platform/organizations/${orgId}/billing/overrides`, { overrides: payload }), 'Excepciones guardadas.')
}

async function saveAddOns(): Promise<void> {
  const payload = Object.entries(addOnQty).map(([key, quantity]) => ({ key, quantity: Math.max(0, Math.floor(quantity)) }))
  await run(() => http.put(`/platform/organizations/${orgId}/billing/add-ons`, { add_ons: payload }), 'Add-ons actualizados.')
}

async function markPaid(id: string): Promise<void> {
  const ok = await confirmDialog.ask({
    title: 'Confirmar pago',
    message: 'Se activará o renovará el plan de la factura y se registrará el cobro.',
    confirmText: 'Confirmar pago',
  })
  if (ok) await run(() => http.post(`/platform/invoices/${id}/mark-paid`))
}

onMounted(load)
</script>

<template>
  <div>
    <RouterLink to="/platform/organizations" class="mb-3 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-700">
      <AppIcon name="chevron-left" :size="16" /> Organizaciones
    </RouterLink>

    <div v-if="loading" class="card p-6"><div class="skeleton h-48 w-full" /></div>
    <ErrorState v-else-if="failed || !org || !billing" @retry="load" />

    <template v-else>
      <PageHeader :title="org.name" :description="`${org.billing_email ?? org.slug} · alta ${date(org.created_at)} · ${org.members_count ?? 0} miembros · ${org.brands_count ?? 0} marcas`">
        <template #actions>
          <StatusBadge :tone="org.status === 'active' ? 'success' : 'danger'" dot>{{ org.status === 'active' ? 'Activa' : 'Suspendida' }}</StatusBadge>
          <button type="button" class="btn-secondary text-sm" :disabled="busy" @click="toggleStatus">
            {{ org.status === 'suspended' ? 'Reactivar' : 'Suspender' }}
          </button>
        </template>
      </PageHeader>

      <div class="grid gap-6 lg:grid-cols-3">
        <!-- Miembros -->
        <section class="card overflow-hidden lg:col-span-2" aria-labelledby="members-title">
          <h2 id="members-title" class="border-b border-slate-100 px-5 py-4 font-semibold text-slate-900 dark:border-slate-800 dark:text-white">
            Miembros <span class="font-normal text-slate-400">({{ org.members.length }})</span>
          </h2>
          <ul class="divide-y divide-slate-100 dark:divide-slate-800">
            <li v-for="m in org.members" :key="m.id" class="flex flex-wrap items-center gap-3 px-5 py-3 text-sm">
              <div class="min-w-0 flex-1">
                <p class="flex flex-wrap items-center gap-1.5 font-medium text-slate-900 dark:text-white">
                  {{ m.name }}
                  <StatusBadge v-if="m.is_owner" tone="brand">Propietario</StatusBadge>
                  <StatusBadge v-if="m.blocked" tone="danger">Cuenta bloqueada</StatusBadge>
                  <StatusBadge v-if="m.status !== 'active'" tone="neutral">{{ m.status }}</StatusBadge>
                </p>
                <p class="truncate text-xs text-slate-400">{{ m.email }} · {{ m.role ?? 'sin rol' }} · último acceso {{ m.last_login_at ? relativeTime(m.last_login_at) : 'nunca' }}</p>
              </div>
              <button v-if="canImpersonate(m)" type="button" class="btn-ghost px-3 py-1 text-xs" @click="impersonate(m)">Impersonar</button>
            </li>
          </ul>
        </section>

        <!-- Marcas -->
        <section class="card overflow-hidden" aria-labelledby="brands-title">
          <h2 id="brands-title" class="border-b border-slate-100 px-5 py-4 font-semibold text-slate-900 dark:border-slate-800 dark:text-white">
            Marcas <span class="font-normal text-slate-400">({{ org.brands.length }})</span>
          </h2>
          <p v-if="org.brands.length === 0" class="p-5 text-sm text-slate-500">Aún no tiene marcas.</p>
          <ul v-else class="divide-y divide-slate-100 dark:divide-slate-800">
            <li v-for="b in org.brands" :key="b.id" class="flex items-center justify-between gap-3 px-5 py-3 text-sm">
              <span class="min-w-0 truncate font-medium text-slate-800 dark:text-slate-100">{{ b.name }}</span>
              <span class="shrink-0 text-xs text-slate-400">{{ b.connections_count }} {{ b.connections_count === 1 ? 'cuenta' : 'cuentas' }}</span>
            </li>
          </ul>
        </section>

        <!-- Suscripción -->
        <section class="card p-5" aria-labelledby="sub-title">
          <h2 id="sub-title" class="font-semibold text-slate-900 dark:text-white">Suscripción</h2>
          <template v-if="billing.subscription">
            <p class="mt-2 text-lg font-bold">{{ billing.subscription.plan_name }}</p>
            <StatusBadge :tone="subTone(billing.subscription.status)" dot>{{ billing.subscription.status_label }}</StatusBadge>
            <dl class="mt-3 space-y-1 text-sm">
              <div class="flex justify-between"><dt class="text-slate-500">Periodicidad</dt><dd>{{ billing.subscription.interval === 'year' ? 'Anual' : 'Mensual' }}</dd></div>
              <div class="flex justify-between"><dt class="text-slate-500">Pago</dt><dd>{{ billing.subscription.gateway ?? '—' }}</dd></div>
              <div class="flex justify-between">
                <dt class="text-slate-500">{{ billing.subscription.status === 'trialing' ? 'Prueba hasta' : 'Periodo hasta' }}</dt>
                <dd>{{ dateLong(billing.subscription.status === 'trialing' ? billing.subscription.trial_ends_at : billing.subscription.current_period_end) }}</dd>
              </div>
            </dl>
            <p v-if="billing.subscription.cancel_at_period_end" class="mt-2 text-xs text-rose-600">Se cancelará al final del periodo.</p>
          </template>
          <p v-else class="mt-2 text-sm text-slate-500">Sin suscripción.</p>

          <div class="mt-5 space-y-4 border-t border-slate-100 pt-4 dark:border-slate-800">
            <div>
              <p class="mb-1.5 text-sm font-medium">Activar plan (cortesía)</p>
              <div class="flex flex-wrap gap-2">
                <label for="op-plan" class="sr-only">Plan</label>
                <select id="op-plan" v-model="planForm.plan" class="input w-auto flex-1">
                  <option v-for="p in plans" :key="p.key" :value="p.key">{{ p.name }}</option>
                </select>
                <label for="op-int" class="sr-only">Periodicidad</label>
                <select id="op-int" v-model="planForm.interval" class="input w-auto">
                  <option value="month">Mensual</option>
                  <option value="year">Anual</option>
                </select>
                <button type="button" class="btn-primary text-sm" :disabled="busy" @click="changePlan">Activar</button>
              </div>
            </div>
            <div>
              <label for="op-trial" class="mb-1.5 block text-sm font-medium">Ampliar prueba</label>
              <div class="flex gap-2">
                <input id="op-trial" v-model.number="trialDays" type="number" min="1" max="365" class="input w-24" />
                <span class="self-center text-sm text-slate-500">días</span>
                <button type="button" class="btn-secondary text-sm" :disabled="busy" @click="extendTrial">Ampliar</button>
              </div>
            </div>
            <div v-if="billing.subscription && !['cancelled', 'expired'].includes(billing.subscription.status)" class="flex flex-wrap gap-2">
              <button type="button" class="btn-ghost text-xs text-rose-600" :disabled="busy" @click="cancel(false)">Cancelar al final del periodo</button>
              <button type="button" class="btn-ghost text-xs text-rose-600" :disabled="busy" @click="cancel(true)">Cancelar ya</button>
            </div>
          </div>
        </section>

        <!-- Límites efectivos -->
        <section class="card p-5 lg:col-span-2" aria-labelledby="ent-title">
          <h2 id="ent-title" class="font-semibold text-slate-900 dark:text-white">Límites y funciones efectivos</h2>
          <p class="text-xs text-slate-500">Plan + add-ons + excepciones.</p>
          <div class="mt-3 grid gap-x-6 gap-y-1.5 text-sm sm:grid-cols-2">
            <div v-for="d in definitions" :key="d.key" class="flex justify-between gap-3 border-b border-slate-50 py-1 dark:border-slate-800/60">
              <span class="text-slate-600 dark:text-slate-300">{{ d.label }}</span>
              <span class="font-medium">
                <template v-if="d.type === 'limit'">{{ billing.usage[d.key] ?? 0 }} / {{ limit(billing.entitlements[d.key]) }}</template>
                <template v-else>{{ billing.entitlements[d.key] ? 'Incluida' : '—' }}</template>
              </span>
            </div>
          </div>
        </section>

        <!-- Excepciones -->
        <section class="card p-5 lg:col-span-2" aria-labelledby="ov-title">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
              <h2 id="ov-title" class="font-semibold text-slate-900 dark:text-white">Excepciones (feature flags)</h2>
              <p class="text-xs text-slate-500">Sustituyen el valor del plan sólo para esta organización.</p>
            </div>
            <div class="flex gap-2">
              <label for="ov-add" class="sr-only">Añadir excepción</label>
              <select id="ov-add" class="input w-auto" @change="addOverride(($event.target as HTMLSelectElement).value); ($event.target as HTMLSelectElement).value = ''">
                <option value="">Añadir excepción…</option>
                <option v-for="d in availableOverrideKeys" :key="d.key" :value="d.key">{{ d.label }}</option>
              </select>
            </div>
          </div>
          <p v-if="overrides.length === 0" class="mt-3 text-sm text-slate-500">Sin excepciones: aplica el plan tal cual.</p>
          <ul v-else class="mt-3 space-y-2">
            <li v-for="(o, i) in overrides" :key="o.key" class="flex flex-wrap items-center gap-2 rounded-lg border border-slate-200 p-2 dark:border-slate-700">
              <span class="w-48 text-sm font-medium">{{ definitionOf(o.key)?.label }}</span>
              <template v-if="definitionOf(o.key)?.type === 'bool'">
                <label class="flex items-center gap-2 text-sm"><input v-model="o.value" type="checkbox" class="rounded border-slate-300 text-brand-600" /> Incluida</label>
              </template>
              <template v-else>
                <input v-model.number="o.value" type="number" min="0" class="input w-28" :disabled="o.unlimited" :aria-label="`Valor de ${definitionOf(o.key)?.label}`" />
                <label class="flex items-center gap-1.5 text-xs"><input v-model="o.unlimited" type="checkbox" class="rounded border-slate-300 text-brand-600" /> Ilimitado</label>
              </template>
              <input v-model="o.note" class="input min-w-40 flex-1" placeholder="Motivo (opcional)" :aria-label="`Motivo de ${definitionOf(o.key)?.label}`" />
              <button type="button" class="grid h-8 w-8 place-items-center rounded-lg text-rose-500 hover:bg-rose-50" :aria-label="`Quitar ${definitionOf(o.key)?.label}`" @click="overrides.splice(i, 1)">
                <AppIcon name="close" :size="14" />
              </button>
            </li>
          </ul>
          <div class="mt-3 flex justify-end">
            <button type="button" class="btn-primary text-sm" :disabled="busy" @click="saveOverrides">Guardar excepciones</button>
          </div>
        </section>

        <!-- Add-ons -->
        <section class="card p-5" aria-labelledby="ao-title">
          <h2 id="ao-title" class="font-semibold text-slate-900 dark:text-white">Add-ons</h2>
          <p v-if="addOnCatalog.length === 0" class="mt-2 text-sm text-slate-500">No hay add-ons en el catálogo.</p>
          <ul v-else class="mt-3 space-y-2">
            <li v-for="a in addOnCatalog" :key="a.key" class="flex items-center justify-between gap-2">
              <label :for="`qty-${a.key}`" class="text-sm">{{ a.name }}</label>
              <input :id="`qty-${a.key}`" v-model.number="addOnQty[a.key]" type="number" min="0" class="input w-20" />
            </li>
          </ul>
          <div v-if="addOnCatalog.length" class="mt-3 flex justify-end">
            <button type="button" class="btn-secondary text-sm" :disabled="busy" @click="saveAddOns">Guardar add-ons</button>
          </div>
        </section>

        <!-- Facturas -->
        <section class="card overflow-hidden lg:col-span-3" aria-labelledby="inv-title">
          <h2 id="inv-title" class="border-b border-slate-100 px-5 py-4 font-semibold text-slate-900 dark:border-slate-800 dark:text-white">Facturas recientes</h2>
          <p v-if="billing.invoices.length === 0" class="p-5 text-sm text-slate-500">Sin facturas.</p>
          <div v-else class="overflow-x-auto">
            <table class="w-full text-sm">
              <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                <tr v-for="inv in billing.invoices" :key="inv.id">
                  <td class="px-5 py-3 font-mono text-xs">{{ inv.number }}</td>
                  <td class="px-5 py-3">{{ inv.description }}</td>
                  <td class="px-5 py-3 text-slate-500">{{ inv.gateway ?? '—' }}</td>
                  <td class="px-5 py-3 text-slate-500">{{ date(inv.paid_at ?? inv.issued_at) }}</td>
                  <td class="px-5 py-3 text-right font-medium">{{ money(inv.amount_cents, inv.currency) }}</td>
                  <td class="px-5 py-3">
                    <StatusBadge :tone="inv.status === 'paid' ? 'success' : inv.status === 'open' ? 'warning' : 'neutral'">
                      {{ inv.status === 'paid' ? 'Pagada' : inv.status === 'open' ? 'Pendiente' : 'Anulada' }}
                    </StatusBadge>
                  </td>
                  <td class="px-5 py-3 text-right">
                    <button v-if="inv.status === 'open'" type="button" class="btn-ghost px-3 py-1 text-xs" :disabled="busy" @click="markPaid(inv.id)">
                      Confirmar pago
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- Eliminar a petición del cliente -->
        <section class="card border-rose-200 p-5 lg:col-span-3 dark:border-rose-900/60" aria-labelledby="org-delete-title">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0 flex-1">
              <h2 id="org-delete-title" class="font-semibold text-rose-700 dark:text-rose-400">Eliminar organización</h2>
              <p class="text-sm text-slate-500">
                Sólo a petición verificada del cliente (p. ej. perdió el acceso de su propietario). Se cancelan publicaciones,
                se desconectan cuentas, se revocan API keys e invitaciones y se cancela la suscripción.
              </p>
            </div>
            <button type="button" class="btn-secondary text-sm text-rose-600" @click="deleteName = ''; deleteErrors = {}; deleteOpen = true">
              Eliminar…
            </button>
          </div>
        </section>
      </div>

      <ModalDialog :open="suspendOpen" title="Suspender organización" size="sm" @close="suspendOpen = false">
        <form class="space-y-4" @submit.prevent="confirmSuspend">
          <p class="text-sm text-slate-600 dark:text-slate-300">
            Nadie de {{ org.name }} podrá usar la organización, no se publicará nada, sus API keys dejarán de responder y
            se pausan sus sincronizaciones hasta que la reactives.
          </p>
          <div>
            <label class="label" for="suspend-reason">Motivo <span class="text-slate-400">(queda en la auditoría)</span></label>
            <textarea id="suspend-reason" v-model="suspendReason" rows="2" maxlength="500" class="input" placeholder="Spam, fraude, petición del cliente…" />
          </div>
          <div class="flex justify-end gap-2">
            <button type="button" class="btn-secondary" @click="suspendOpen = false">Cancelar</button>
            <button type="submit" class="btn-danger" :disabled="busy">Suspender</button>
          </div>
        </form>
      </ModalDialog>

      <ModalDialog :open="deleteOpen" title="Eliminar organización" size="sm" @close="deleteOpen = false">
        <form class="space-y-4" @submit.prevent="deleteOrganization">
          <div>
            <label class="label" for="platform-delete-name">Escribe <strong>{{ org.name }}</strong> para confirmar</label>
            <input id="platform-delete-name" v-model="deleteName" required autocomplete="off" class="input" />
            <p v-if="deleteErrors.confirm_name" class="mt-1 text-xs text-rose-600">{{ deleteErrors.confirm_name[0] }}</p>
          </div>
          <div class="flex justify-end gap-2">
            <button type="button" class="btn-secondary" @click="deleteOpen = false">Cancelar</button>
            <button type="submit" class="btn-danger" :disabled="deleting || deleteName.trim().toLowerCase() !== org.name.trim().toLowerCase()">
              <Spinner v-if="deleting" :size="18" /> Eliminar para siempre
            </button>
          </div>
        </form>
      </ModalDialog>
    </template>
  </div>
</template>
