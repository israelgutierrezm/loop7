<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import http from '@/services/http'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage } from '@/utils/errors'
import ErrorState from '@/components/ui/ErrorState.vue'
import AppIcon from '@/components/AppIcon.vue'

interface Provider {
  key: string
  name: string
  is_enabled: boolean
  is_default: boolean
  text_model: string | null
  image_model: string | null
  text_models: string[]
  image_models: string[]
  requires_credentials: boolean
  configured_credentials: string[]
}

const toasts = useToastStore()
const providers = ref<Provider[]>([])
const loading = ref(true)
const failed = ref(false)
const forms = reactive<Record<string, { api_key: string }>>({})
const tests = reactive<Record<string, { loading: boolean; ok?: boolean; message?: string }>>({})

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const { data } = await http.get('/platform/ai-providers')
    providers.value = data.data
    for (const p of providers.value) forms[p.key] = { api_key: '' }
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

async function update(p: Provider, patch: Record<string, unknown>): Promise<void> {
  try {
    const { data } = await http.put(`/platform/ai-providers/${p.key}`, patch)
    Object.assign(p, data.data)
    // Sólo un proveedor por defecto: refrescar el resto.
    if (patch.is_default) await load()
    toasts.success('Proveedor actualizado.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function test(p: Provider): Promise<void> {
  tests[p.key] = { loading: true }
  try {
    const { data } = await http.post(`/platform/ai-providers/${p.key}/test`)
    tests[p.key] = { loading: false, ok: data.data.ok, message: data.data.message }
  } catch (e) {
    tests[p.key] = { loading: false, ok: false, message: apiErrorMessage(e) }
  }
}

async function saveCredentials(p: Provider): Promise<void> {
  const api_key = forms[p.key].api_key
  if (!api_key) {
    toasts.error('Introduce la API key.')
    return
  }
  try {
    const { data } = await http.put(`/platform/ai-providers/${p.key}/credentials`, {
      credentials: { api_key },
    })
    Object.assign(p, data.data)
    forms[p.key].api_key = ''
    toasts.success('Credenciales guardadas de forma cifrada.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

onMounted(load)
</script>

<template>
  <div>
    <h1 class="mb-1 text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Proveedores de IA</h1>
    <p class="mb-6 text-sm text-slate-500">Habilita proveedores, elige el modelo por defecto y configura credenciales.</p>

    <div v-if="loading" class="card p-6"><div class="skeleton h-32 w-full" /></div>
    <ErrorState v-else-if="failed" @retry="load" />

    <div v-else class="space-y-4">
      <div v-for="p in providers" :key="p.key" class="card p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="flex items-center gap-3">
            <span class="grid h-10 w-10 place-items-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-950/50">
              <AppIcon name="ai" :size="20" />
            </span>
            <div>
              <p class="flex items-center gap-2 font-semibold text-slate-900 dark:text-white">
                {{ p.name }}
                <span
                  v-if="p.is_default"
                  class="rounded-full bg-brand-100 px-2 py-0.5 text-[10px] font-bold uppercase text-brand-700 dark:bg-brand-950/60 dark:text-brand-300"
                >
                  Por defecto
                </span>
              </p>
              <p class="text-xs text-slate-400">{{ p.key }}</p>
            </div>
          </div>
          <div class="flex items-center gap-3">
            <button class="btn-secondary text-xs" :disabled="tests[p.key]?.loading" @click="test(p)">
              <AppIcon name="refresh" :size="14" :class="tests[p.key]?.loading ? 'animate-spin' : ''" /> Probar conexión
            </button>
            <button
              v-if="!p.is_default && p.is_enabled"
              class="btn-secondary text-xs"
              @click="update(p, { is_default: true })"
            >
              Marcar por defecto
            </button>
            <label class="flex cursor-pointer items-center gap-2 text-sm">
              <input
                type="checkbox"
                class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                :checked="p.is_enabled"
                @change="update(p, { is_enabled: ($event.target as HTMLInputElement).checked })"
              />
              {{ p.is_enabled ? 'Habilitado' : 'Deshabilitado' }}
            </label>
          </div>
        </div>

        <!-- Resultado de la prueba de conexión -->
        <p
          v-if="tests[p.key]?.message"
          class="mt-2 text-xs font-medium"
          :class="tests[p.key]?.ok ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'"
        >
          {{ tests[p.key]?.ok ? '✓' : '✗' }} {{ tests[p.key]?.message }}
        </p>

        <!-- Modelos por defecto -->
        <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
          <label class="text-sm">
            <span class="mb-1 block text-slate-500">Modelo de texto</span>
            <input
              class="input"
              :value="p.text_model ?? ''"
              placeholder="p. ej. gpt-4o-mini"
              @change="update(p, { config: { text_model: ($event.target as HTMLInputElement).value } })"
            />
          </label>
          <label class="text-sm">
            <span class="mb-1 block text-slate-500">Modelo de imagen</span>
            <input
              class="input"
              :value="p.image_model ?? ''"
              placeholder="p. ej. dall-e-3"
              @change="update(p, { config: { image_model: ($event.target as HTMLInputElement).value } })"
            />
          </label>
        </div>

        <!-- Credenciales -->
        <div v-if="p.requires_credentials" class="mt-4 rounded-lg border border-slate-100 p-4 dark:border-slate-800">
          <div class="mb-3 flex items-center justify-between">
            <p class="text-sm font-medium text-slate-700 dark:text-slate-300">Credenciales</p>
            <span
              v-if="p.configured_credentials.length"
              class="rounded-md bg-emerald-100 px-2 py-0.5 text-xs text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300"
            >
              Configurado · ••••
            </span>
          </div>
          <div class="flex flex-wrap items-end gap-2">
            <input
              v-model="forms[p.key].api_key"
              type="password"
              class="input flex-1"
              placeholder="API key"
              autocomplete="off"
            />
            <button class="btn-primary text-sm" @click="saveCredentials(p)">Guardar</button>
          </div>
        </div>
        <p v-else class="mt-4 text-xs text-slate-400">Proveedor de prueba: no requiere credenciales.</p>
      </div>
    </div>
  </div>
</template>
