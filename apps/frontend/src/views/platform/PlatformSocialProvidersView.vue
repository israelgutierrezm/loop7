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
  configured_credentials: string[]
}

const toasts = useToastStore()
const providers = ref<Provider[]>([])
const loading = ref(true)
const failed = ref(false)
const forms = reactive<Record<string, { client_id: string; client_secret: string }>>({})

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const { data } = await http.get('/platform/social-providers')
    providers.value = data.data
    for (const p of providers.value) forms[p.key] = { client_id: '', client_secret: '' }
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

async function toggle(p: Provider): Promise<void> {
  try {
    const { data } = await http.put(`/platform/social-providers/${p.key}`, { is_enabled: !p.is_enabled })
    Object.assign(p, data.data)
    toasts.success('Proveedor actualizado.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function saveCredentials(p: Provider): Promise<void> {
  const form = forms[p.key]
  const credentials: Record<string, string> = {}
  if (form.client_id) credentials.client_id = form.client_id
  if (form.client_secret) credentials.client_secret = form.client_secret
  if (Object.keys(credentials).length === 0) {
    toasts.error('Introduce al menos una credencial.')
    return
  }
  try {
    const { data } = await http.put(`/platform/social-providers/${p.key}/credentials`, { credentials })
    Object.assign(p, data.data)
    form.client_id = ''
    form.client_secret = ''
    toasts.success('Credenciales guardadas de forma cifrada.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

onMounted(load)
</script>

<template>
  <div>
    <h1 class="mb-6 text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Integraciones sociales</h1>

    <div v-if="loading" class="card p-6"><div class="skeleton h-32 w-full" /></div>
    <ErrorState v-else-if="failed" @retry="load" />

    <div v-else class="space-y-4">
      <div v-for="p in providers" :key="p.key" class="card p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="flex items-center gap-3">
            <span class="grid h-10 w-10 place-items-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-950/50">
              <AppIcon name="social" :size="20" />
            </span>
            <div>
              <p class="font-semibold text-slate-900 dark:text-white">{{ p.name }}</p>
              <p class="text-xs text-slate-400">
                {{ p.configured_credentials.length ? p.configured_credentials.join(', ') : 'Sin credenciales' }}
              </p>
            </div>
          </div>
          <label class="flex cursor-pointer items-center gap-2 text-sm">
            <input
              type="checkbox"
              class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
              :checked="p.is_enabled"
              @change="toggle(p)"
            />
            {{ p.is_enabled ? 'Habilitado' : 'Deshabilitado' }}
          </label>
        </div>

        <div v-if="p.key !== 'fake'" class="mt-4 rounded-lg border border-slate-100 p-4 dark:border-slate-800">
          <p class="mb-3 text-sm font-medium text-slate-700 dark:text-slate-300">Credenciales OAuth</p>
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <input v-model="forms[p.key].client_id" type="text" class="input" placeholder="Client ID" autocomplete="off" />
            <input v-model="forms[p.key].client_secret" type="password" class="input" placeholder="Client Secret" autocomplete="off" />
          </div>
          <div class="mt-3 flex justify-end">
            <button class="btn-primary text-sm" @click="saveCredentials(p)">Guardar credenciales</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
