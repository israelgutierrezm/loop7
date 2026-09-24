<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import http from '@/services/http'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage } from '@/utils/errors'
import PageHeader from '@/components/ui/PageHeader.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import CopyField from '@/components/ui/CopyField.vue'
import Spinner from '@/components/ui/Spinner.vue'
import AppIcon from '@/components/AppIcon.vue'

interface Credential { key: string; environment: string; masked: string }
interface Gateway {
  key: string
  name: string
  is_enabled: boolean
  environment: string
  currency: string
  country: string | null
  instructions: string | null
  is_offline: boolean
  webhook_url: string | null
  credentials: Credential[]
}
interface Field { key: string; label: string; secret: boolean; hint?: string }

const toasts = useToastStore()
const gateways = ref<Gateway[]>([])
const loading = ref(true)
const failed = ref(false)
const tests = reactive<Record<string, { loading: boolean; ok?: boolean; message?: string }>>({})
const forms = reactive<Record<string, { environment: string; values: Record<string, string> }>>({})
const settings = reactive<Record<string, { currency: string; country: string; instructions: string }>>({})

// Campos de credenciales por pasarela (se guardan cifrados y se muestran enmascarados).
const fields: Record<string, Field[]> = {
  stripe: [
    { key: 'secret_key', label: 'Secret key (sk_…)', secret: true },
    { key: 'public_key', label: 'Publishable key (pk_…)', secret: false },
    { key: 'webhook_secret', label: 'Signing secret del webhook (whsec_…)', secret: true },
  ],
  mercadopago: [
    { key: 'secret_key', label: 'Access Token', secret: true },
    { key: 'public_key', label: 'Public key', secret: false },
    { key: 'webhook_secret', label: 'Clave secreta de las notificaciones', secret: true },
  ],
  openpay: [
    { key: 'merchant_id', label: 'ID de comercio', secret: false },
    { key: 'secret_key', label: 'Llave privada (sk_…)', secret: true },
    { key: 'public_key', label: 'Llave pública (pk_…)', secret: false },
    { key: 'webhook_secret', label: 'Usuario y contraseña del webhook', secret: true, hint: 'Formato usuario:contraseña (autenticación básica configurada en Openpay).' },
  ],
}

const currencies = ['USD', 'MXN', 'COP', 'PEN', 'ARS', 'CLP', 'BRL', 'EUR']

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const { data } = await http.get('/platform/payment-gateways')
    gateways.value = data.data
    for (const g of gateways.value) {
      forms[g.key] = { environment: g.environment, values: {} }
      settings[g.key] = { currency: g.currency, country: g.country ?? 'mx', instructions: g.instructions ?? '' }
    }
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

function replace(updated: Gateway): void {
  const index = gateways.value.findIndex((g) => g.key === updated.key)
  if (index >= 0) gateways.value[index] = updated
}

async function update(g: Gateway, patch: Record<string, unknown>, message = 'Pasarela actualizada.'): Promise<void> {
  try {
    const { data } = await http.put(`/platform/payment-gateways/${g.key}`, patch)
    replace(data.data)
    toasts.success(message)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function test(g: Gateway): Promise<void> {
  tests[g.key] = { loading: true }
  try {
    const { data } = await http.post(`/platform/payment-gateways/${g.key}/test`)
    tests[g.key] = { loading: false, ok: data.data.ok, message: data.data.message }
  } catch (e) {
    tests[g.key] = { loading: false, ok: false, message: apiErrorMessage(e) }
  }
}

async function saveCredentials(g: Gateway): Promise<void> {
  const form = forms[g.key]
  const credentials = Object.fromEntries(Object.entries(form.values).filter(([, v]) => v.trim() !== ''))
  if (Object.keys(credentials).length === 0) {
    toasts.error('Introduce al menos una credencial.')
    return
  }
  try {
    const { data } = await http.put(`/platform/payment-gateways/${g.key}/credentials`, {
      environment: form.environment,
      credentials,
    })
    replace(data.data)
    form.values = {}
    tests[g.key] = { loading: false }
    toasts.success('Credenciales guardadas de forma cifrada.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

function saveSettings(g: Gateway): void {
  const s = settings[g.key]
  const patch: Record<string, unknown> = { currency: s.currency }
  if (g.key === 'openpay') patch.country = s.country
  if (g.is_offline) patch.instructions = s.instructions.trim() || null
  update(g, patch, 'Ajustes de cobro guardados.')
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader
      title="Pasarelas de pago"
      description="Habilita las formas de pago de tus clientes. Las credenciales se guardan cifradas y sólo se muestran enmascaradas."
    />

    <div v-if="loading" class="card p-6"><div class="skeleton h-32 w-full" /></div>
    <ErrorState v-else-if="failed" @retry="load" />

    <div v-else class="space-y-4">
      <section v-for="g in gateways" :key="g.key" class="card" :aria-labelledby="`gw-${g.key}`">
        <div class="flex flex-wrap items-center justify-between gap-3 p-5">
          <div class="flex items-center gap-3">
            <span class="grid h-10 w-10 place-items-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-950/50">
              <AppIcon name="billing" :size="20" />
            </span>
            <div>
              <h2 :id="`gw-${g.key}`" class="font-semibold text-slate-900 dark:text-white">{{ g.name }}</h2>
              <p class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                <StatusBadge :tone="g.is_enabled ? 'success' : 'neutral'" dot>{{ g.is_enabled ? 'Habilitada' : 'Deshabilitada' }}</StatusBadge>
                <StatusBadge :tone="g.environment === 'production' ? 'warning' : 'info'">
                  {{ g.environment === 'production' ? 'Producción' : 'Test / Sandbox' }}
                </StatusBadge>
                <span>Cobra en {{ g.currency }}</span>
              </p>
            </div>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <button type="button" class="btn-secondary text-sm" :disabled="tests[g.key]?.loading" @click="test(g)">
              <Spinner v-if="tests[g.key]?.loading" :size="16" /> Probar conexión
            </button>
            <label :for="`env-${g.key}`" class="sr-only">Entorno activo</label>
            <select
              :id="`env-${g.key}`"
              class="input w-auto py-1.5 text-sm"
              :value="g.environment"
              @change="update(g, { environment: ($event.target as HTMLSelectElement).value })"
            >
              <option value="test">Test / Sandbox</option>
              <option value="production">Producción</option>
            </select>
            <button type="button" class="btn text-sm" :class="g.is_enabled ? 'btn-secondary' : 'btn-primary'" @click="update(g, { is_enabled: !g.is_enabled })">
              {{ g.is_enabled ? 'Deshabilitar' : 'Habilitar' }}
            </button>
          </div>
        </div>

        <p
          v-if="tests[g.key]?.message"
          class="mx-5 mb-4 rounded-lg px-3 py-2 text-sm"
          :class="tests[g.key]?.ok ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' : 'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300'"
          role="status"
        >
          {{ tests[g.key]?.ok ? '✓' : '✗' }} {{ tests[g.key]?.message }}
        </p>

        <div class="grid gap-6 border-t border-slate-100 p-5 lg:grid-cols-2 dark:border-slate-800">
          <!-- Ajustes de cobro -->
          <form class="space-y-3" @submit.prevent="saveSettings(g)">
            <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-200">Cobro</h3>
            <div class="grid gap-3 sm:grid-cols-2">
              <div>
                <label :for="`cur-${g.key}`" class="label">Moneda</label>
                <select :id="`cur-${g.key}`" v-model="settings[g.key].currency" class="input">
                  <option v-for="c in currencies" :key="c" :value="c">{{ c }}</option>
                </select>
              </div>
              <div v-if="g.key === 'openpay'">
                <label :for="`country-${g.key}`" class="label">País de la cuenta</label>
                <select :id="`country-${g.key}`" v-model="settings[g.key].country" class="input">
                  <option value="mx">México</option>
                  <option value="co">Colombia</option>
                  <option value="pe">Perú</option>
                </select>
              </div>
            </div>
            <p class="text-xs text-slate-400">Los planes necesitan un precio en esta moneda para poder contratarse con esta pasarela.</p>
            <div v-if="g.is_offline">
              <label :for="`ins-${g.key}`" class="label">Instrucciones de pago</label>
              <textarea
                :id="`ins-${g.key}`"
                v-model="settings[g.key].instructions"
                rows="4"
                class="input"
                placeholder="Ej. Transfiere a la CLABE 0123… a nombre de … e indica el número de factura. Envía el comprobante a pagos@…"
              />
              <p class="mt-1 text-xs text-slate-400">El cliente las ve al elegir esta forma de pago; el plan se activa cuando confirmas el pago en Pagos y facturas.</p>
            </div>
            <div class="flex justify-end">
              <button type="submit" class="btn-secondary text-sm">Guardar ajustes</button>
            </div>
          </form>

          <!-- Credenciales -->
          <div v-if="!g.is_offline" class="space-y-3">
            <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-200">Credenciales</h3>
            <div v-if="g.credentials.length" class="flex flex-wrap gap-2">
              <span
                v-for="c in g.credentials"
                :key="c.environment + c.key"
                class="rounded-md bg-slate-100 px-2 py-1 font-mono text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300"
              >
                {{ c.environment === 'production' ? 'prod' : 'test' }} · {{ c.key }}: {{ c.masked }}
              </span>
            </div>
            <form class="space-y-3 rounded-lg border border-slate-100 p-4 dark:border-slate-800" @submit.prevent="saveCredentials(g)">
              <div>
                <label :for="`cenv-${g.key}`" class="label">Entorno de estas credenciales</label>
                <select :id="`cenv-${g.key}`" v-model="forms[g.key].environment" class="input">
                  <option value="test">Test / Sandbox</option>
                  <option value="production">Producción</option>
                </select>
              </div>
              <div v-for="f in fields[g.key] ?? []" :key="f.key">
                <label :for="`cred-${g.key}-${f.key}`" class="label">{{ f.label }}</label>
                <input
                  :id="`cred-${g.key}-${f.key}`"
                  v-model="forms[g.key].values[f.key]"
                  :type="f.secret ? 'password' : 'text'"
                  class="input font-mono text-xs"
                  autocomplete="off"
                  placeholder="Deja vacío para conservar el valor guardado"
                />
                <p v-if="f.hint" class="mt-1 text-xs text-slate-400">{{ f.hint }}</p>
              </div>
              <div class="flex justify-end">
                <button type="submit" class="btn-primary text-sm">Guardar credenciales</button>
              </div>
            </form>
            <CopyField
              v-if="g.webhook_url"
              label="URL de webhooks (regístrala en el panel de la pasarela)"
              :value="g.webhook_url"
            />
          </div>
          <p v-else class="self-center text-sm text-slate-500">
            Pago fuera de línea: no requiere credenciales ni webhooks. Confirma los pagos recibidos en Pagos y facturas.
          </p>
        </div>
      </section>
    </div>
  </div>
</template>
