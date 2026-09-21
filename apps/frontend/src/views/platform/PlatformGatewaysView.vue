<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import http from '@/services/http'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage } from '@/utils/errors'
import ErrorState from '@/components/ui/ErrorState.vue'
import AppIcon from '@/components/AppIcon.vue'

interface Credential { key: string; environment: string; masked: string }
interface Gateway {
  key: string
  name: string
  is_enabled: boolean
  environment: string
  credentials: Credential[]
}

const toasts = useToastStore()
const gateways = ref<Gateway[]>([])
const loading = ref(true)
const failed = ref(false)

// Formulario de credenciales por gateway.
const forms = reactive<Record<string, { environment: string; secret_key: string; public_key: string; webhook_secret: string }>>({})

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const { data } = await http.get('/platform/payment-gateways')
    gateways.value = data.data
    for (const g of gateways.value) {
      forms[g.key] = { environment: g.environment, secret_key: '', public_key: '', webhook_secret: '' }
    }
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

async function update(g: Gateway, patch: Partial<Gateway>): Promise<void> {
  try {
    const { data } = await http.put(`/platform/payment-gateways/${g.key}`, patch)
    Object.assign(g, data.data)
    toasts.success('Pasarela actualizada.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function saveCredentials(g: Gateway): Promise<void> {
  const form = forms[g.key]
  const credentials: Record<string, string> = {}
  if (form.secret_key) credentials.secret_key = form.secret_key
  if (form.public_key) credentials.public_key = form.public_key
  if (form.webhook_secret) credentials.webhook_secret = form.webhook_secret
  if (Object.keys(credentials).length === 0) {
    toasts.error('Introduce al menos una credencial.')
    return
  }
  try {
    const { data } = await http.put(`/platform/payment-gateways/${g.key}/credentials`, {
      environment: form.environment,
      credentials,
    })
    Object.assign(g, data.data)
    form.secret_key = ''
    form.public_key = ''
    form.webhook_secret = ''
    toasts.success('Credenciales guardadas de forma cifrada.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

onMounted(load)
</script>

<template>
  <div>
    <h1 class="mb-6 text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Pasarelas de pago</h1>

    <div v-if="loading" class="card p-6"><div class="skeleton h-32 w-full" /></div>
    <ErrorState v-else-if="failed" @retry="load" />

    <div v-else class="space-y-4">
      <div v-for="g in gateways" :key="g.key" class="card p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="flex items-center gap-3">
            <span class="grid h-10 w-10 place-items-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-950/50">
              <AppIcon name="billing" :size="20" />
            </span>
            <div>
              <p class="font-semibold text-slate-900 dark:text-white">{{ g.name }}</p>
              <p class="text-xs text-slate-400">{{ g.key }}</p>
            </div>
          </div>
          <div class="flex items-center gap-3">
            <select
              class="input w-auto py-1.5 text-sm"
              :value="g.environment"
              @change="update(g, { environment: ($event.target as HTMLSelectElement).value })"
            >
              <option value="test">Test / Sandbox</option>
              <option value="production">Producción</option>
            </select>
            <label class="flex cursor-pointer items-center gap-2 text-sm">
              <input
                type="checkbox"
                class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                :checked="g.is_enabled"
                @change="update(g, { is_enabled: ($event.target as HTMLInputElement).checked })"
              />
              {{ g.is_enabled ? 'Habilitada' : 'Deshabilitada' }}
            </label>
          </div>
        </div>

        <!-- Credenciales existentes (enmascaradas) -->
        <div v-if="g.credentials.length" class="mt-4 flex flex-wrap gap-2">
          <span
            v-for="c in g.credentials"
            :key="c.environment + c.key"
            class="rounded-md bg-slate-100 px-2 py-1 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300"
          >
            {{ c.environment }} · {{ c.key }}: {{ c.masked }}
          </span>
        </div>

        <!-- Configurar credenciales -->
        <div v-if="g.key !== 'manual'" class="mt-4 rounded-lg border border-slate-100 p-4 dark:border-slate-800">
          <p class="mb-3 text-sm font-medium text-slate-700 dark:text-slate-300">Configurar credenciales</p>
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <select v-model="forms[g.key].environment" class="input">
              <option value="test">Test / Sandbox</option>
              <option value="production">Producción</option>
            </select>
            <input v-model="forms[g.key].secret_key" type="password" class="input" placeholder="Secret key" autocomplete="off" />
            <input v-model="forms[g.key].public_key" type="text" class="input" placeholder="Public key" autocomplete="off" />
            <input v-model="forms[g.key].webhook_secret" type="password" class="input" placeholder="Webhook secret" autocomplete="off" />
          </div>
          <div class="mt-3 flex justify-end">
            <button class="btn-primary text-sm" @click="saveCredentials(g)">Guardar credenciales</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
