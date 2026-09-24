<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import http from '@/services/http'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage } from '@/utils/errors'
import PageHeader from '@/components/ui/PageHeader.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import Spinner from '@/components/ui/Spinner.vue'
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
  supports_images: boolean
  requires_credentials: boolean
  configured_credentials: string[]
}

const toasts = useToastStore()
const providers = ref<Provider[]>([])
const loading = ref(true)
const failed = ref(false)
const forms = reactive<Record<string, { api_key: string }>>({})
const tests = reactive<Record<string, { loading: boolean; ok?: boolean; message?: string }>>({})
const refreshing = ref<string | null>(null)

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

function replace(updated: Provider): void {
  const index = providers.value.findIndex((p) => p.key === updated.key)
  if (index >= 0) providers.value[index] = updated
}

async function update(p: Provider, patch: Record<string, unknown>): Promise<void> {
  try {
    const { data } = await http.put(`/platform/ai-providers/${p.key}`, patch)
    // Sólo un proveedor por defecto: al cambiarlo se refresca la lista completa.
    if (patch.is_default) await load()
    else replace(data.data)
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

async function refreshModels(p: Provider): Promise<void> {
  refreshing.value = p.key
  try {
    const { data } = await http.post(`/platform/ai-providers/${p.key}/models`)
    replace(data.data)
    toasts.success(data.message ?? 'Modelos actualizados.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    refreshing.value = null
  }
}

async function saveCredentials(p: Provider): Promise<void> {
  const apiKey = forms[p.key].api_key.trim()
  if (!apiKey) {
    toasts.error('Introduce la API key.')
    return
  }
  try {
    const { data } = await http.put(`/platform/ai-providers/${p.key}/credentials`, { credentials: { api_key: apiKey } })
    replace(data.data)
    forms[p.key].api_key = ''
    tests[p.key] = { loading: false }
    toasts.success('API key guardada de forma cifrada.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

function setModel(p: Provider, kind: 'text_model' | 'image_model', event: Event): void {
  const value = (event.target as HTMLInputElement).value.trim()
  if (value === (p[kind] ?? '')) return
  update(p, { config: { [kind]: value || null } })
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader
      title="Proveedores de IA"
      description="Habilita proveedores, elige cuál se usa por defecto y con qué modelo. Las API keys se guardan cifradas."
    />

    <div v-if="loading" class="card p-6"><div class="skeleton h-32 w-full" /></div>
    <ErrorState v-else-if="failed" @retry="load" />

    <div v-else class="space-y-4">
      <section v-for="p in providers" :key="p.key" class="card" :aria-labelledby="`ai-${p.key}`">
        <div class="flex flex-wrap items-center justify-between gap-3 p-5">
          <div class="flex items-center gap-3">
            <span class="grid h-10 w-10 place-items-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-950/50">
              <AppIcon name="ai" :size="20" />
            </span>
            <div>
              <h2 :id="`ai-${p.key}`" class="flex items-center gap-2 font-semibold text-slate-900 dark:text-white">
                {{ p.name }}
                <StatusBadge v-if="p.is_default" tone="brand">Por defecto</StatusBadge>
              </h2>
              <p class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                <StatusBadge :tone="p.is_enabled ? 'success' : 'neutral'" dot>
                  {{ p.is_enabled ? 'Habilitado' : 'Deshabilitado' }}
                </StatusBadge>
                <span v-if="p.requires_credentials">
                  {{ p.configured_credentials.length ? 'API key configurada' : 'Sin API key' }}
                </span>
                <span v-else>No requiere credenciales</span>
              </p>
            </div>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <button type="button" class="btn-secondary text-sm" :disabled="tests[p.key]?.loading" @click="test(p)">
              <Spinner v-if="tests[p.key]?.loading" :size="16" /> Probar conexión
            </button>
            <button
              v-if="!p.is_default && p.is_enabled"
              type="button"
              class="btn-secondary text-sm"
              @click="update(p, { is_default: true })"
            >
              Usar por defecto
            </button>
            <button
              type="button"
              class="btn text-sm"
              :class="p.is_enabled ? 'btn-secondary' : 'btn-primary'"
              @click="update(p, { is_enabled: !p.is_enabled })"
            >
              {{ p.is_enabled ? 'Deshabilitar' : 'Habilitar' }}
            </button>
          </div>
        </div>

        <p
          v-if="tests[p.key]?.message"
          class="mx-5 mb-4 rounded-lg px-3 py-2 text-sm"
          :class="tests[p.key]?.ok ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' : 'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300'"
          role="status"
        >
          {{ tests[p.key]?.ok ? '✓' : '✗' }} {{ tests[p.key]?.message }}
        </p>

        <div class="grid gap-6 border-t border-slate-100 p-5 lg:grid-cols-2 dark:border-slate-800">
          <!-- Modelos -->
          <div class="space-y-3">
            <div class="flex items-center justify-between gap-2">
              <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-200">Modelos</h3>
              <button
                v-if="p.requires_credentials"
                type="button"
                class="btn-ghost px-2 py-1 text-xs"
                :disabled="refreshing === p.key || !p.configured_credentials.length"
                :title="p.configured_credentials.length ? '' : 'Guarda la API key primero'"
                @click="refreshModels(p)"
              >
                <AppIcon name="refresh" :size="14" :class="refreshing === p.key ? 'animate-spin' : ''" />
                Actualizar lista de modelos
              </button>
            </div>
            <div>
              <label :for="`tm-${p.key}`" class="label">Modelo de texto</label>
              <input
                :id="`tm-${p.key}`"
                class="input font-mono text-xs"
                :value="p.text_model ?? ''"
                :list="`tml-${p.key}`"
                placeholder="Elige de la lista o escribe el id del modelo"
                @change="setModel(p, 'text_model', $event)"
              />
              <datalist :id="`tml-${p.key}`">
                <option v-for="m in p.text_models" :key="m" :value="m" />
              </datalist>
            </div>
            <div v-if="p.supports_images">
              <label :for="`im-${p.key}`" class="label">Modelo de imagen</label>
              <input
                :id="`im-${p.key}`"
                class="input font-mono text-xs"
                :value="p.image_model ?? ''"
                :list="`iml-${p.key}`"
                placeholder="Elige de la lista o escribe el id del modelo"
                @change="setModel(p, 'image_model', $event)"
              />
              <datalist :id="`iml-${p.key}`">
                <option v-for="m in p.image_models" :key="m" :value="m" />
              </datalist>
            </div>
            <p v-else class="text-xs text-slate-400">Este proveedor sólo genera texto.</p>
          </div>

          <!-- Credenciales -->
          <form v-if="p.requires_credentials" class="space-y-3" @submit.prevent="saveCredentials(p)">
            <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-200">API key</h3>
            <div>
              <label :for="`key-${p.key}`" class="label">API key de {{ p.name }}</label>
              <input
                :id="`key-${p.key}`"
                v-model="forms[p.key].api_key"
                type="password"
                class="input"
                :placeholder="p.configured_credentials.length ? '•••••••• guardada · escribe para reemplazar' : ''"
                autocomplete="new-password"
              />
              <p class="mt-1 text-xs text-slate-400">Se guarda cifrada y nunca vuelve a mostrarse.</p>
            </div>
            <div class="flex justify-end">
              <button type="submit" class="btn-primary text-sm">Guardar API key</button>
            </div>
          </form>
          <p v-else class="text-sm text-slate-500">Proveedor simulado para desarrollo y pruebas: responde sin red.</p>
        </div>
      </section>
    </div>
  </div>
</template>
