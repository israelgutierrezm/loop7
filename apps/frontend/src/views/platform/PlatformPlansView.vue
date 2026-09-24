<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import http from '@/services/http'
import { useConfirmStore } from '@/stores/confirm'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage } from '@/utils/errors'
import { limit, money } from '@/utils/format'
import PageHeader from '@/components/ui/PageHeader.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import ModalDialog from '@/components/ui/ModalDialog.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import Spinner from '@/components/ui/Spinner.vue'
import AppIcon from '@/components/AppIcon.vue'

interface EntitlementDef { key: string; type: 'limit' | 'bool'; label: string }
interface Price { interval: 'month' | 'year'; currency: string; amount: string }
interface Plan {
  key: string
  name: string
  description: string | null
  is_active: boolean
  is_public: boolean
  sort_order: number
  trial_days: number
  prices: { interval: 'month' | 'year'; currency: string; amount_cents: number }[]
  entitlements: Record<string, number | boolean>
  subscribers: number
}
interface AddOn {
  key: string
  name: string
  description: string | null
  entitlement_key: string
  quantity_per_unit: number
  price_cents: number
  currency: string
  is_active: boolean
}

const toasts = useToastStore()
const confirmDialog = useConfirmStore()

const loading = ref(true)
const failed = ref(false)
const plans = ref<Plan[]>([])
const addOns = ref<AddOn[]>([])
const definitions = ref<EntitlementDef[]>([])
const saving = ref(false)

const limitDefs = computed(() => definitions.value.filter((d) => d.type === 'limit'))
const featureDefs = computed(() => definitions.value.filter((d) => d.type === 'bool'))

// ---------- Plan (crear/editar) ----------
const editing = ref<string | null>(null) // key del plan o '' para nuevo
const form = reactive({
  key: '',
  name: '',
  description: '',
  trial_days: 14,
  sort_order: 0,
  is_active: true,
  is_public: true,
  prices: [] as Price[],
  limits: {} as Record<string, { value: number; unlimited: boolean }>,
  features: {} as Record<string, boolean>,
})

function openPlan(plan: Plan | null): void {
  editing.value = plan?.key ?? ''
  form.key = plan?.key ?? ''
  form.name = plan?.name ?? ''
  form.description = plan?.description ?? ''
  form.trial_days = plan?.trial_days ?? 14
  form.sort_order = plan?.sort_order ?? plans.value.length + 1
  form.is_active = plan?.is_active ?? true
  form.is_public = plan?.is_public ?? true
  form.prices = (plan?.prices ?? [{ interval: 'month', currency: 'USD', amount_cents: 0 }]).map((p) => ({
    interval: p.interval,
    currency: p.currency,
    amount: (p.amount_cents / 100).toFixed(2),
  }))
  form.limits = {}
  form.features = {}
  for (const d of limitDefs.value) {
    const v = plan?.entitlements[d.key]
    form.limits[d.key] = { value: typeof v === 'number' && v >= 0 ? v : 0, unlimited: v === -1 }
  }
  for (const d of featureDefs.value) form.features[d.key] = plan?.entitlements[d.key] === true
}

async function savePlan(): Promise<void> {
  if (!form.name.trim() || (editing.value === '' && !form.key.trim())) {
    toasts.error('La clave y el nombre son obligatorios.')
    return
  }
  const entitlements: Record<string, number | boolean> = { ...form.features }
  for (const [key, l] of Object.entries(form.limits)) entitlements[key] = l.unlimited ? -1 : Math.max(0, Math.floor(l.value))
  const payload = {
    name: form.name.trim(),
    description: form.description.trim() || null,
    trial_days: form.trial_days,
    sort_order: form.sort_order,
    is_active: form.is_active,
    is_public: form.is_public,
    prices: form.prices.map((p) => ({
      interval: p.interval,
      currency: p.currency.trim().toUpperCase(),
      amount_cents: Math.round(Number(p.amount) * 100),
    })),
    entitlements,
  }
  saving.value = true
  try {
    if (editing.value === '') {
      await http.post('/platform/plans', { ...payload, key: form.key.trim() })
      toasts.success('Plan creado.')
    } else {
      await http.put(`/platform/plans/${editing.value}`, payload)
      toasts.success('Plan actualizado.')
    }
    editing.value = null
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

async function removePlan(plan: Plan): Promise<void> {
  const ok = await confirmDialog.ask({
    title: `Eliminar el plan ${plan.name}`,
    message: 'Sólo se pueden eliminar planes sin suscripciones. Si tiene clientes, desactívalo para que no se pueda contratar.',
    confirmText: 'Eliminar',
    danger: true,
  })
  if (!ok) return
  try {
    await http.delete(`/platform/plans/${plan.key}`)
    toasts.success('Plan eliminado.')
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function toggleActive(plan: Plan): Promise<void> {
  try {
    await http.put(`/platform/plans/${plan.key}`, { is_active: !plan.is_active })
    plan.is_active = !plan.is_active
    toasts.success(plan.is_active ? 'Plan activado.' : 'Plan desactivado: ya no se puede contratar.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

// ---------- Add-ons ----------
const editingAddOn = ref<string | null>(null)
const addOnForm = reactive({ key: '', name: '', description: '', entitlement_key: '', quantity_per_unit: 1, price: '0.00', currency: 'USD', is_active: true })

function openAddOn(addOn: AddOn | null): void {
  editingAddOn.value = addOn?.key ?? ''
  addOnForm.key = addOn?.key ?? ''
  addOnForm.name = addOn?.name ?? ''
  addOnForm.description = addOn?.description ?? ''
  addOnForm.entitlement_key = addOn?.entitlement_key ?? limitDefs.value[0]?.key ?? ''
  addOnForm.quantity_per_unit = addOn?.quantity_per_unit ?? 1
  addOnForm.price = ((addOn?.price_cents ?? 0) / 100).toFixed(2)
  addOnForm.currency = addOn?.currency ?? 'USD'
  addOnForm.is_active = addOn?.is_active ?? true
}

async function saveAddOn(): Promise<void> {
  const payload = {
    name: addOnForm.name.trim(),
    description: addOnForm.description.trim() || null,
    entitlement_key: addOnForm.entitlement_key,
    quantity_per_unit: addOnForm.quantity_per_unit,
    price_cents: Math.round(Number(addOnForm.price) * 100),
    currency: addOnForm.currency.trim().toUpperCase(),
    is_active: addOnForm.is_active,
  }
  saving.value = true
  try {
    if (editingAddOn.value === '') await http.post('/platform/add-ons', { ...payload, key: addOnForm.key.trim() })
    else await http.put(`/platform/add-ons/${editingAddOn.value}`, payload)
    toasts.success('Add-on guardado.')
    editingAddOn.value = null
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

function labelOf(key: string): string {
  return definitions.value.find((d) => d.key === key)?.label ?? key
}

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const [p, a, d] = await Promise.all([
      http.get('/platform/plans'),
      http.get('/platform/add-ons'),
      http.get('/platform/entitlements'),
    ])
    plans.value = p.data.data
    addOns.value = a.data.data
    definitions.value = d.data.data
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
    <PageHeader title="Planes" description="Precios, límites y funciones de cada plan. Los cambios aplican de inmediato a sus suscriptores.">
      <template #actions>
        <button type="button" class="btn-primary text-sm" :disabled="loading" @click="openPlan(null)">
          <AppIcon name="plus" :size="16" /> Nuevo plan
        </button>
      </template>
    </PageHeader>

    <div v-if="loading" class="card p-6"><div class="skeleton h-40 w-full" /></div>
    <ErrorState v-else-if="failed" @retry="load" />

    <template v-else>
      <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <article v-for="plan in plans" :key="plan.key" class="card flex flex-col p-5" :class="plan.is_active ? '' : 'opacity-70'">
          <div class="flex items-start justify-between gap-2">
            <div>
              <h2 class="font-semibold text-slate-900 dark:text-white">{{ plan.name }}</h2>
              <p class="font-mono text-xs text-slate-400">{{ plan.key }}</p>
            </div>
            <div class="flex flex-wrap justify-end gap-1">
              <StatusBadge :tone="plan.is_active ? 'success' : 'neutral'">{{ plan.is_active ? 'Activo' : 'Inactivo' }}</StatusBadge>
              <StatusBadge v-if="!plan.is_public" tone="warning">Oculto</StatusBadge>
            </div>
          </div>
          <p v-if="plan.description" class="mt-2 text-sm text-slate-500">{{ plan.description }}</p>
          <ul class="mt-3 space-y-0.5 text-sm">
            <li v-for="p in plan.prices" :key="p.interval + p.currency" class="flex justify-between">
              <span class="text-slate-500">{{ p.interval === 'year' ? 'Anual' : 'Mensual' }} · {{ p.currency }}</span>
              <span class="font-medium">{{ money(p.amount_cents, p.currency) }}</span>
            </li>
            <li v-if="plan.prices.length === 0" class="text-amber-600">Sin precios: no se puede contratar.</li>
          </ul>
          <p class="mt-3 text-xs text-slate-500">
            {{ limit(plan.entitlements['brands.max']) }} marcas · {{ limit(plan.entitlements['social_accounts.max']) }} cuentas ·
            {{ limit(plan.entitlements['ai_credits.month']) }} créditos IA · trial {{ plan.trial_days }} días
          </p>
          <div class="mt-auto flex items-center justify-between gap-2 pt-4">
            <span class="text-xs text-slate-500">{{ plan.subscribers }} suscriptor(es) activos</span>
            <div class="flex gap-1">
              <button type="button" class="btn-ghost px-2 py-1 text-xs" @click="toggleActive(plan)">
                {{ plan.is_active ? 'Desactivar' : 'Activar' }}
              </button>
              <button type="button" class="btn-secondary px-3 py-1 text-xs" @click="openPlan(plan)">Editar</button>
              <button
                v-if="plan.subscribers === 0"
                type="button"
                class="btn-ghost px-2 py-1 text-xs text-rose-600"
                :aria-label="`Eliminar ${plan.name}`"
                @click="removePlan(plan)"
              >
                <AppIcon name="close" :size="14" />
              </button>
            </div>
          </div>
        </article>
      </div>

      <!-- Add-ons -->
      <section class="card mt-8" aria-labelledby="addons-title">
        <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-4 dark:border-slate-800">
          <div>
            <h2 id="addons-title" class="font-semibold text-slate-900 dark:text-white">Add-ons</h2>
            <p class="text-xs text-slate-500">Ampliaciones que se asignan a una organización desde su ficha (suman a los límites del plan).</p>
          </div>
          <button type="button" class="btn-secondary text-sm" @click="openAddOn(null)"><AppIcon name="plus" :size="14" /> Nuevo add-on</button>
        </div>
        <p v-if="addOns.length === 0" class="p-5 text-sm text-slate-500">Sin add-ons.</p>
        <ul v-else class="divide-y divide-slate-100 dark:divide-slate-800">
          <li v-for="a in addOns" :key="a.key" class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
            <div>
              <p class="text-sm font-medium text-slate-900 dark:text-white">
                {{ a.name }} <StatusBadge v-if="!a.is_active" tone="neutral">Inactivo</StatusBadge>
              </p>
              <p class="text-xs text-slate-500">+{{ a.quantity_per_unit }} {{ labelOf(a.entitlement_key) }} por unidad · {{ money(a.price_cents, a.currency) }}</p>
            </div>
            <button type="button" class="btn-ghost px-3 py-1 text-xs" @click="openAddOn(a)">Editar</button>
          </li>
        </ul>
      </section>
    </template>

    <!-- Modal plan -->
    <ModalDialog
      :open="editing !== null"
      :title="editing === '' ? 'Nuevo plan' : `Editar plan ${form.name}`"
      size="xl"
      @close="editing = null"
    >
      <form id="plan-form" class="space-y-6" @submit.prevent="savePlan">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <label for="pl-key" class="label">Clave</label>
            <input id="pl-key" v-model="form.key" class="input font-mono" :disabled="editing !== ''" placeholder="p. ej. pymes" pattern="[a-z0-9-]+" />
          </div>
          <div class="lg:col-span-2">
            <label for="pl-name" class="label">Nombre</label>
            <input id="pl-name" v-model="form.name" class="input" required />
          </div>
          <div>
            <label for="pl-trial" class="label">Días de prueba</label>
            <input id="pl-trial" v-model.number="form.trial_days" type="number" min="0" max="365" class="input" />
          </div>
          <div class="sm:col-span-2 lg:col-span-3">
            <label for="pl-desc" class="label">Descripción</label>
            <input id="pl-desc" v-model="form.description" class="input" maxlength="300" />
          </div>
          <div>
            <label for="pl-sort" class="label">Orden</label>
            <input id="pl-sort" v-model.number="form.sort_order" type="number" min="0" class="input" />
          </div>
        </div>
        <div class="flex flex-wrap gap-6 text-sm">
          <label class="flex items-center gap-2"><input v-model="form.is_active" type="checkbox" class="rounded border-slate-300 text-brand-600" /> Se puede contratar</label>
          <label class="flex items-center gap-2"><input v-model="form.is_public" type="checkbox" class="rounded border-slate-300 text-brand-600" /> Visible en el catálogo</label>
        </div>

        <fieldset>
          <legend class="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-200">Precios</legend>
          <p class="mb-2 text-xs text-slate-500">Cada pasarela cobra en su moneda: añade un precio por moneda que uses (p. ej. USD para Stripe, MXN para Mercado Pago u Openpay).</p>
          <div v-for="(p, i) in form.prices" :key="i" class="mb-2 flex flex-wrap items-center gap-2">
            <select v-model="p.interval" class="input w-32" :aria-label="`Periodicidad del precio ${i + 1}`">
              <option value="month">Mensual</option>
              <option value="year">Anual</option>
            </select>
            <input v-model="p.currency" class="input w-24 uppercase" maxlength="3" :aria-label="`Moneda del precio ${i + 1}`" />
            <input v-model="p.amount" type="number" min="0" step="0.01" class="input w-36" :aria-label="`Importe del precio ${i + 1}`" />
            <button type="button" class="grid h-9 w-9 place-items-center rounded-lg text-rose-500 hover:bg-rose-50" :aria-label="`Quitar precio ${i + 1}`" @click="form.prices.splice(i, 1)">
              <AppIcon name="close" :size="14" />
            </button>
          </div>
          <button type="button" class="btn-ghost px-2 py-1 text-xs" @click="form.prices.push({ interval: 'month', currency: 'USD', amount: '0.00' })">
            <AppIcon name="plus" :size="14" /> Añadir precio
          </button>
        </fieldset>

        <fieldset>
          <legend class="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-200">Límites</legend>
          <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <div v-for="d in limitDefs" :key="d.key" class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
              <label :for="`lim-${d.key}`" class="label">{{ d.label }}</label>
              <div class="flex items-center gap-3">
                <input
                  :id="`lim-${d.key}`"
                  v-model.number="form.limits[d.key].value"
                  type="number"
                  min="0"
                  class="input"
                  :disabled="form.limits[d.key].unlimited"
                />
                <label class="flex shrink-0 items-center gap-1.5 text-xs text-slate-600 dark:text-slate-300">
                  <input v-model="form.limits[d.key].unlimited" type="checkbox" class="rounded border-slate-300 text-brand-600" /> Ilimitado
                </label>
              </div>
            </div>
          </div>
        </fieldset>

        <fieldset>
          <legend class="mb-2 text-sm font-semibold text-slate-800 dark:text-slate-200">Funciones incluidas</legend>
          <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            <label v-for="d in featureDefs" :key="d.key" class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700">
              <input v-model="form.features[d.key]" type="checkbox" class="rounded border-slate-300 text-brand-600" /> {{ d.label }}
            </label>
          </div>
        </fieldset>
      </form>
      <template #footer>
        <button type="button" class="btn-secondary text-sm" @click="editing = null">Cancelar</button>
        <button type="submit" form="plan-form" class="btn-primary text-sm" :disabled="saving">
          <Spinner v-if="saving" :size="16" /> Guardar plan
        </button>
      </template>
    </ModalDialog>

    <!-- Modal add-on -->
    <ModalDialog :open="editingAddOn !== null" :title="editingAddOn === '' ? 'Nuevo add-on' : 'Editar add-on'" @close="editingAddOn = null">
      <form id="addon-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="saveAddOn">
        <div>
          <label for="ad-key" class="label">Clave</label>
          <input id="ad-key" v-model="addOnForm.key" class="input font-mono" :disabled="editingAddOn !== ''" pattern="[a-z0-9-]+" required />
        </div>
        <div>
          <label for="ad-name" class="label">Nombre</label>
          <input id="ad-name" v-model="addOnForm.name" class="input" required />
        </div>
        <div>
          <label for="ad-ent" class="label">Amplía</label>
          <select id="ad-ent" v-model="addOnForm.entitlement_key" class="input">
            <option v-for="d in limitDefs" :key="d.key" :value="d.key">{{ d.label }}</option>
          </select>
        </div>
        <div>
          <label for="ad-qty" class="label">Cantidad por unidad</label>
          <input id="ad-qty" v-model.number="addOnForm.quantity_per_unit" type="number" min="1" class="input" />
        </div>
        <div>
          <label for="ad-price" class="label">Precio</label>
          <input id="ad-price" v-model="addOnForm.price" type="number" min="0" step="0.01" class="input" />
        </div>
        <div>
          <label for="ad-cur" class="label">Moneda</label>
          <input id="ad-cur" v-model="addOnForm.currency" class="input uppercase" maxlength="3" />
        </div>
        <div class="sm:col-span-2">
          <label for="ad-desc" class="label">Descripción</label>
          <input id="ad-desc" v-model="addOnForm.description" class="input" />
        </div>
        <label class="flex items-center gap-2 text-sm sm:col-span-2">
          <input v-model="addOnForm.is_active" type="checkbox" class="rounded border-slate-300 text-brand-600" /> Activo
        </label>
      </form>
      <template #footer>
        <button type="button" class="btn-secondary text-sm" @click="editingAddOn = null">Cancelar</button>
        <button type="submit" form="addon-form" class="btn-primary text-sm" :disabled="saving">Guardar</button>
      </template>
    </ModalDialog>
  </div>
</template>
