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
import ProviderIcon from '@/components/social/ProviderIcon.vue'

interface Provider {
  key: string
  name: string
  is_enabled: boolean
  requires_app: boolean
  configured_credentials: string[]
  shares_app_with: string | null
  uses_shared_credentials: boolean
  api_version: { label: string; value: string | null; default: string; example: string; hint: string } | null
  credential_labels: { client_id: string; client_secret: string }
  scopes: string[]
  default_scopes: string[]
  /** Permisos que no se piden por defecto y activan funciones (p. ej. borrar en Threads). */
  optional_scopes: { scope: string; purpose: string }[]
  setup: {
    redirect_uri: string
    redirect_hint: string
    data_deletion_url: string | null
    deauthorize_url: string | null
    webhook_url: string | null
    privacy_url: string
    terms_url: string
  }
}

interface TestResult { ok: boolean; message: string }

const toasts = useToastStore()
const providers = ref<Provider[]>([])
const loading = ref(true)
const failed = ref(false)
const forms = reactive<Record<string, { client_id: string; client_secret: string; api_version: string; scopes: string }>>({})
const saving = ref<string | null>(null)
const testing = ref<string | null>(null)
const results = reactive<Record<string, TestResult | undefined>>({})
const expanded = reactive<Record<string, boolean>>({})

function fill(p: Provider): void {
  forms[p.key] = {
    client_id: '',
    client_secret: '',
    api_version: p.api_version?.value ?? '',
    scopes: p.scopes.join(', '),
  }
}

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const { data } = await http.get('/platform/social-providers')
    providers.value = data.data
    providers.value.forEach(fill)
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

function replace(updated: Provider): void {
  const index = providers.value.findIndex((p) => p.key === updated.key)
  if (index >= 0) providers.value[index] = updated
}

async function toggle(p: Provider): Promise<void> {
  try {
    const { data } = await http.put(`/platform/social-providers/${p.key}`, { is_enabled: !p.is_enabled })
    replace(data.data)
    toasts.success(data.data.is_enabled ? `${p.name} habilitado para los clientes.` : `${p.name} deshabilitado.`)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function saveCredentials(p: Provider): Promise<void> {
  const form = forms[p.key]
  const credentials: Record<string, string> = {}
  if (form.client_id.trim()) credentials.client_id = form.client_id.trim()
  if (form.client_secret.trim()) credentials.client_secret = form.client_secret.trim()
  if (Object.keys(credentials).length === 0) {
    toasts.error(`Introduce el ${p.credential_labels.client_id} o el ${p.credential_labels.client_secret}.`)
    return
  }
  saving.value = p.key
  try {
    const { data } = await http.put(`/platform/social-providers/${p.key}/credentials`, { credentials })
    replace(data.data)
    form.client_id = ''
    form.client_secret = ''
    results[p.key] = undefined
    toasts.success('Credenciales guardadas de forma cifrada.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    saving.value = null
  }
}

function scopeList(p: Provider): string[] {
  return forms[p.key].scopes.split(/[\s,]+/).map((s) => s.trim()).filter(Boolean)
}

function addScope(p: Provider, scope: string): void {
  forms[p.key].scopes = [...scopeList(p), scope].join(', ')
}

async function saveAdvanced(p: Provider): Promise<void> {
  const form = forms[p.key]
  const scopes = scopeList(p)
  saving.value = p.key
  try {
    const payload: Record<string, unknown> = {
      scopes: scopes.join(',') === p.default_scopes.join(',') ? null : scopes,
    }
    if (p.api_version !== null) payload.api_version = form.api_version.trim() || null
    const { data } = await http.put(`/platform/social-providers/${p.key}`, payload)
    replace(data.data)
    fill(data.data)
    toasts.success('Ajustes guardados.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    saving.value = null
  }
}

function resetScopes(p: Provider): void {
  forms[p.key].scopes = p.default_scopes.join(', ')
}

async function test(p: Provider): Promise<void> {
  testing.value = p.key
  try {
    const { data } = await http.post(`/platform/social-providers/${p.key}/test`)
    results[p.key] = data.data
  } catch (e) {
    results[p.key] = { ok: false, message: apiErrorMessage(e) }
  } finally {
    testing.value = null
  }
}

function credentialsLabel(p: Provider): string {
  if (!p.requires_app) return 'No requiere credenciales'
  if (p.configured_credentials.length) return 'Credenciales propias configuradas'
  if (p.uses_shared_credentials) return 'Usa la app de Facebook'
  return 'Sin credenciales'
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader
      title="Integraciones sociales"
      description="Habilita las redes que podrán conectar tus clientes y configura las apps de cada proveedor."
    />

    <div v-if="loading" class="card p-6"><div class="skeleton h-32 w-full" /></div>
    <ErrorState v-else-if="failed" @retry="load" />

    <div v-else class="space-y-4">
      <section v-for="p in providers" :key="p.key" class="card" :aria-labelledby="`prov-${p.key}`">
        <div class="flex flex-wrap items-center justify-between gap-3 p-5">
          <div class="flex items-center gap-3">
            <ProviderIcon :provider="p.key" :size="40" />
            <div>
              <h2 :id="`prov-${p.key}`" class="font-semibold text-slate-900 dark:text-white">{{ p.name }}</h2>
              <p class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                <StatusBadge :tone="p.is_enabled ? 'success' : 'neutral'" dot>
                  {{ p.is_enabled ? 'Habilitado' : 'Deshabilitado' }}
                </StatusBadge>
                <span>{{ credentialsLabel(p) }}</span>
                <span v-if="p.api_version">· {{ p.api_version.label }} {{ p.api_version.value ?? p.api_version.default }}</span>
              </p>
            </div>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <button
              v-if="p.requires_app"
              type="button"
              class="btn-secondary text-sm"
              :disabled="testing === p.key"
              @click="test(p)"
            >
              <Spinner v-if="testing === p.key" :size="16" /> Probar conexión
            </button>
            <button
              type="button"
              class="btn text-sm"
              :class="p.is_enabled ? 'btn-secondary' : 'btn-primary'"
              @click="toggle(p)"
            >
              {{ p.is_enabled ? 'Deshabilitar' : 'Habilitar' }}
            </button>
          </div>
        </div>

        <p
          v-if="results[p.key]"
          class="mx-5 mb-4 rounded-lg px-3 py-2 text-sm"
          :class="results[p.key]?.ok ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' : 'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300'"
          role="status"
        >
          {{ results[p.key]?.ok ? '✓' : '✗' }} {{ results[p.key]?.message }}
        </p>

        <div v-if="p.requires_app" class="grid gap-6 border-t border-slate-100 p-5 lg:grid-cols-2 dark:border-slate-800">
          <!-- Credenciales -->
          <form class="space-y-3" @submit.prevent="saveCredentials(p)">
            <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-200">Credenciales de la app</h3>
            <p v-if="p.shares_app_with" class="text-xs text-slate-500">
              {{ p.name }} usa la misma app de Meta que Facebook: si dejas esto vacío se usan sus credenciales.
            </p>
            <div>
              <label :for="`cid-${p.key}`" class="label">{{ p.credential_labels.client_id }}</label>
              <input
                :id="`cid-${p.key}`"
                v-model="forms[p.key].client_id"
                type="text"
                class="input"
                :placeholder="p.configured_credentials.includes('client_id') ? 'Guardado · escribe para reemplazar' : ''"
                autocomplete="off"
              />
            </div>
            <div>
              <label :for="`csec-${p.key}`" class="label">{{ p.credential_labels.client_secret }}</label>
              <input
                :id="`csec-${p.key}`"
                v-model="forms[p.key].client_secret"
                type="password"
                class="input"
                :placeholder="p.configured_credentials.includes('client_secret') ? '•••••••• guardado · escribe para reemplazar' : ''"
                autocomplete="new-password"
              />
            </div>
            <div class="flex justify-end">
              <button type="submit" class="btn-primary text-sm" :disabled="saving === p.key">Guardar credenciales</button>
            </div>
          </form>

          <!-- Datos para registrar en la app del proveedor -->
          <div class="space-y-3">
            <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-200">Datos para la app del proveedor</h3>
            <CopyField
              label="URI de redireccionamiento OAuth válido"
              :value="p.setup.redirect_uri"
              :hint="p.setup.redirect_hint"
            />
            <CopyField
              v-if="p.setup.webhook_url"
              label="URL de webhooks"
              :value="p.setup.webhook_url"
              hint="Regístrala en tu app de TikTok → Webhooks: avisa cuando alguien quita el acceso y cuando un video pasa a público."
            />
            <template v-if="p.setup.data_deletion_url">
              <CopyField label="URL de devolución de llamada de eliminación de datos" :value="p.setup.data_deletion_url" />
              <CopyField
                v-if="p.setup.deauthorize_url"
                label="URL de devolución de llamada para desautorizar"
                :value="p.setup.deauthorize_url"
              />
              <CopyField label="URL de la política de privacidad" :value="p.setup.privacy_url" />
              <CopyField label="URL de las condiciones del servicio" :value="p.setup.terms_url" />
            </template>
          </div>
        </div>

        <!-- Avanzado -->
        <div v-if="p.requires_app" class="border-t border-slate-100 dark:border-slate-800">
          <button
            type="button"
            class="flex w-full items-center justify-between px-5 py-3 text-left text-sm font-medium text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800/50"
            :aria-expanded="expanded[p.key] ? 'true' : 'false'"
            @click="expanded[p.key] = !expanded[p.key]"
          >
            Ajustes avanzados (versión de API y permisos)
            <span aria-hidden="true">{{ expanded[p.key] ? '−' : '+' }}</span>
          </button>
          <form v-if="expanded[p.key]" class="grid gap-4 px-5 pb-5 lg:grid-cols-3" @submit.prevent="saveAdvanced(p)">
            <div v-if="p.api_version">
              <label :for="`gv-${p.key}`" class="label">{{ p.api_version.label }}</label>
              <input
                :id="`gv-${p.key}`"
                v-model="forms[p.key].api_version"
                class="input"
                :placeholder="`${p.api_version.default} (por defecto)`"
              />
              <p class="mt-1 text-xs text-slate-400">{{ p.api_version.hint }}</p>
            </div>
            <div :class="p.api_version ? 'lg:col-span-2' : 'lg:col-span-3'">
              <label :for="`sc-${p.key}`" class="label">Permisos (scopes) solicitados</label>
              <textarea :id="`sc-${p.key}`" v-model="forms[p.key].scopes" rows="2" class="input font-mono text-xs" />
              <p class="mt-1 text-xs text-slate-400">
                Separados por coma. Cada permiso debe estar aprobado en la revisión de la app.
                <button type="button" class="text-brand-600 hover:underline" @click="resetScopes(p)">Restablecer</button>
              </p>
              <div v-if="p.optional_scopes.length" class="mt-3 rounded-lg bg-slate-50 p-3 text-xs dark:bg-slate-800/40">
                <p class="mb-1.5 font-medium text-slate-600 dark:text-slate-300">
                  Permisos opcionales: actívalos primero en la app de la red; las cuentas ya conectadas deben reconectarse para obtenerlos.
                </p>
                <ul class="space-y-1">
                  <li v-for="o in p.optional_scopes" :key="o.scope" class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                    <code class="font-mono text-slate-700 dark:text-slate-200">{{ o.scope }}</code>
                    <span class="text-slate-500">{{ o.purpose }}</span>
                    <span v-if="scopeList(p).includes(o.scope)" class="text-emerald-600 dark:text-emerald-400">En la lista</span>
                    <button v-else type="button" class="text-brand-600 hover:underline" :aria-label="`Añadir ${o.scope} a los permisos`" @click="addScope(p, o.scope)">
                      Añadir
                    </button>
                  </li>
                </ul>
              </div>
            </div>
            <div class="flex justify-end lg:col-span-3">
              <button type="submit" class="btn-primary text-sm" :disabled="saving === p.key">Guardar ajustes</button>
            </div>
          </form>
        </div>
      </section>
    </div>
  </div>
</template>
