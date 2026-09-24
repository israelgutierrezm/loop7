<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import http from '@/services/http'
import { usePublicConfigStore } from '@/stores/publicConfig'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage } from '@/utils/errors'
import PageHeader from '@/components/ui/PageHeader.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import Spinner from '@/components/ui/Spinner.vue'

const toasts = useToastStore()
const publicConfig = usePublicConfigStore()

const loading = ref(true)
const failed = ref(false)
const saving = ref<string | null>(null)
const plans = ref<{ key: string; name: string }[]>([])

const company = reactive({ name: '', legal_name: '', tax_id: '', contact_email: '', support_email: '', country: '', address: '' })
const registration = reactive({ open: true })
const billing = reactive({ trial_plan: '', trial_days: 14, grace_days: 7 })
const announcement = reactive({ enabled: false, message: '', tone: 'info' as 'info' | 'warning' })

function fill(s: Record<string, string | number | boolean>): void {
  for (const key of Object.keys(company) as (keyof typeof company)[]) company[key] = String(s[`company.${key}`] ?? '')
  registration.open = Boolean(s['registration.open'])
  billing.trial_plan = String(s['billing.trial_plan'] ?? '')
  billing.trial_days = Number(s['billing.trial_days'] ?? 14)
  billing.grace_days = Number(s['billing.grace_days'] ?? 7)
  announcement.enabled = Boolean(s['announcement.enabled'])
  announcement.message = String(s['announcement.message'] ?? '')
  announcement.tone = s['announcement.tone'] === 'warning' ? 'warning' : 'info'
}

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const [s, p] = await Promise.all([http.get('/platform/settings'), http.get('/platform/settings/trial-plans')])
    fill(s.data.data)
    plans.value = p.data.data
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

async function save(section: string, payload: Record<string, unknown>): Promise<void> {
  saving.value = section
  try {
    const { data } = await http.put('/platform/settings', payload)
    fill(data.data)
    await publicConfig.load(true)
    toasts.success('Configuración guardada.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    saving.value = null
  }
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader title="Configuración" description="Datos de la empresa, alta de clientes, reglas de facturación y avisos del sistema." />

    <div v-if="loading" class="card p-6"><div class="skeleton h-48 w-full" /></div>
    <ErrorState v-else-if="failed" @retry="load" />

    <div v-else class="grid gap-6 lg:grid-cols-2">
      <!-- Empresa -->
      <form class="card space-y-4 p-6 lg:col-span-2" aria-labelledby="set-company" @submit.prevent="save('company', { company })">
        <div>
          <h2 id="set-company" class="font-semibold text-slate-900 dark:text-white">Datos de la empresa</h2>
          <p class="text-sm text-slate-500">Aparecen en la política de privacidad, los términos, la página de borrado de datos y las facturas.</p>
        </div>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <div>
            <label for="c-name" class="label">Nombre comercial</label>
            <input id="c-name" v-model="company.name" class="input" required />
          </div>
          <div>
            <label for="c-legal" class="label">Razón social</label>
            <input id="c-legal" v-model="company.legal_name" class="input" />
          </div>
          <div>
            <label for="c-tax" class="label">RFC / identificación fiscal</label>
            <input id="c-tax" v-model="company.tax_id" class="input" />
          </div>
          <div>
            <label for="c-email" class="label">Correo de contacto legal</label>
            <input id="c-email" v-model="company.contact_email" type="email" class="input" />
          </div>
          <div>
            <label for="c-support" class="label">Correo de soporte</label>
            <input id="c-support" v-model="company.support_email" type="email" class="input" />
          </div>
          <div>
            <label for="c-country" class="label">País / jurisdicción</label>
            <input id="c-country" v-model="company.country" class="input" />
          </div>
          <div class="sm:col-span-2 lg:col-span-3">
            <label for="c-address" class="label">Domicilio</label>
            <input id="c-address" v-model="company.address" class="input" />
          </div>
        </div>
        <div class="flex justify-end">
          <button type="submit" class="btn-primary text-sm" :disabled="saving !== null">
            <Spinner v-if="saving === 'company'" :size="16" /> Guardar datos
          </button>
        </div>
      </form>

      <!-- Registro -->
      <form class="card space-y-4 p-6" aria-labelledby="set-reg" @submit.prevent="save('registration', { registration })">
        <div>
          <h2 id="set-reg" class="font-semibold text-slate-900 dark:text-white">Alta de clientes</h2>
          <p class="text-sm text-slate-500">Cierra el registro público para operar sólo por invitación (beta privada, ventas asistidas).</p>
        </div>
        <label class="flex items-center gap-3 text-sm">
          <input v-model="registration.open" type="checkbox" class="rounded border-slate-300 text-brand-600" />
          Permitir que cualquiera cree una cuenta desde /registro
        </label>
        <div class="flex justify-end">
          <button type="submit" class="btn-primary text-sm" :disabled="saving !== null">
            <Spinner v-if="saving === 'registration'" :size="16" /> Guardar
          </button>
        </div>
      </form>

      <!-- Billing -->
      <form class="card space-y-4 p-6" aria-labelledby="set-billing" @submit.prevent="save('billing', { billing })">
        <div>
          <h2 id="set-billing" class="font-semibold text-slate-900 dark:text-white">Reglas de facturación</h2>
          <p class="text-sm text-slate-500">Prueba gratuita de las cuentas nuevas y días de gracia tras un pago no recibido.</p>
        </div>
        <div class="grid gap-4 sm:grid-cols-3">
          <div class="sm:col-span-3">
            <label for="b-plan" class="label">Plan de prueba</label>
            <select id="b-plan" v-model="billing.trial_plan" class="input">
              <option v-for="p in plans" :key="p.key" :value="p.key">{{ p.name }}</option>
            </select>
          </div>
          <div>
            <label for="b-days" class="label">Días de prueba</label>
            <input id="b-days" v-model.number="billing.trial_days" type="number" min="1" max="365" class="input" />
          </div>
          <div>
            <label for="b-grace" class="label">Días de gracia</label>
            <input id="b-grace" v-model.number="billing.grace_days" type="number" min="0" max="60" class="input" />
          </div>
        </div>
        <p class="text-xs text-slate-400">Si el plan de prueba define sus propios días de prueba, se usan esos.</p>
        <div class="flex justify-end">
          <button type="submit" class="btn-primary text-sm" :disabled="saving !== null">
            <Spinner v-if="saving === 'billing'" :size="16" /> Guardar
          </button>
        </div>
      </form>

      <!-- Aviso -->
      <form class="card space-y-4 p-6 lg:col-span-2" aria-labelledby="set-ann" @submit.prevent="save('announcement', { announcement })">
        <div>
          <h2 id="set-ann" class="font-semibold text-slate-900 dark:text-white">Aviso del sistema</h2>
          <p class="text-sm text-slate-500">Banner visible para todos los usuarios del panel (mantenimientos, novedades, incidencias).</p>
        </div>
        <div class="grid gap-4 sm:grid-cols-4">
          <div class="sm:col-span-3">
            <label for="a-msg" class="label">Mensaje</label>
            <input id="a-msg" v-model="announcement.message" class="input" maxlength="300" placeholder="Ej. Mantenimiento programado el domingo de 2:00 a 3:00 (UTC-6)" />
          </div>
          <div>
            <label for="a-tone" class="label">Tipo</label>
            <select id="a-tone" v-model="announcement.tone" class="input">
              <option value="info">Informativo</option>
              <option value="warning">Advertencia</option>
            </select>
          </div>
        </div>
        <label class="flex items-center gap-3 text-sm">
          <input v-model="announcement.enabled" type="checkbox" class="rounded border-slate-300 text-brand-600" />
          Mostrar el aviso
        </label>
        <div class="flex justify-end">
          <button type="submit" class="btn-primary text-sm" :disabled="saving !== null">
            <Spinner v-if="saving === 'announcement'" :size="16" /> Guardar aviso
          </button>
        </div>
      </form>
    </div>
  </div>
</template>
