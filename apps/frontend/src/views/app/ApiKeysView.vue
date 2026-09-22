<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import http from '@/services/http'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage } from '@/utils/errors'
import PageHeader from '@/components/ui/PageHeader.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import ModalDialog from '@/components/ui/ModalDialog.vue'
import AppIcon from '@/components/AppIcon.vue'

interface ApiKey {
  id: string
  name: string
  prefix: string
  scopes: string[]
  is_active: boolean
  last_used_at: string | null
  expires_at: string | null
  created_at: string | null
}

const toasts = useToastStore()
const keys = ref<ApiKey[]>([])
const scopeCatalog = ref<Record<string, string>>({})
const loading = ref(true)
const failed = ref(false)
const modalOpen = ref(false)
const saving = ref(false)
const createdKey = ref<string | null>(null)

const form = reactive({ name: '', scopes: [] as string[], expires_at: '' })

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const { data } = await http.get('/api-keys')
    keys.value = data.data.keys
    scopeCatalog.value = data.data.scopes
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

function openCreate(): void {
  form.name = ''
  form.scopes = []
  form.expires_at = ''
  createdKey.value = null
  modalOpen.value = true
}

async function create(): Promise<void> {
  if (!form.name.trim() || form.scopes.length === 0) {
    toasts.error('Indica un nombre y al menos un scope.')
    return
  }
  saving.value = true
  try {
    const payload: Record<string, unknown> = { name: form.name, scopes: form.scopes }
    if (form.expires_at) payload.expires_at = new Date(form.expires_at).toISOString()
    const { data } = await http.post('/api-keys', payload)
    createdKey.value = data.data.key
    toasts.success('API key creada.')
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

async function copyKey(): Promise<void> {
  if (!createdKey.value) return
  try {
    await navigator.clipboard.writeText(createdKey.value)
    toasts.success('Copiada al portapapeles.')
  } catch {
    toasts.error('No se pudo copiar.')
  }
}

async function revoke(k: ApiKey): Promise<void> {
  if (!confirm(`¿Revocar la API key «${k.name}»? Dejará de funcionar de inmediato.`)) return
  try {
    await http.delete(`/api-keys/${k.id}`)
    keys.value = keys.value.filter((x) => x.id !== k.id)
    toasts.success('Revocada.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

function fmt(value: string | null): string {
  return value ? new Date(value).toLocaleDateString('es', { dateStyle: 'medium' }) : '—'
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader title="API y accesos" description="Claves de API con permisos (scopes) para integraciones y MCP.">
      <template #actions>
        <button class="btn-primary text-sm" @click="openCreate"><AppIcon name="plus" :size="16" /> Nueva API key</button>
      </template>
    </PageHeader>

    <div v-if="loading" class="card p-6"><div class="skeleton h-40 w-full" /></div>
    <ErrorState v-else-if="failed" @retry="load" />
    <EmptyState
      v-else-if="keys.length === 0"
      icon="key"
      title="Sin API keys"
      description="Crea una clave para conectar herramientas externas o un cliente MCP."
    >
      <template #action>
        <button class="btn-primary text-sm" @click="openCreate">Nueva API key</button>
      </template>
    </EmptyState>

    <div v-else class="space-y-3">
      <div v-for="k in keys" :key="k.id" class="card flex flex-wrap items-center justify-between gap-3 p-4">
        <div class="min-w-0">
          <div class="flex items-center gap-2">
            <span class="grid h-9 w-9 place-items-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-950/50">
              <AppIcon name="key" :size="18" />
            </span>
            <div class="min-w-0">
              <p class="truncate font-semibold text-slate-900 dark:text-white">{{ k.name }}</p>
              <p class="truncate font-mono text-xs text-slate-400">{{ k.prefix }}••••</p>
            </div>
          </div>
          <div class="mt-2 flex flex-wrap gap-1">
            <span v-for="s in k.scopes" :key="s" class="rounded-md bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ s }}</span>
          </div>
        </div>
        <div class="flex items-center gap-4">
          <div class="text-right text-xs text-slate-400">
            <p>Último uso: {{ fmt(k.last_used_at) }}</p>
            <p>Creada: {{ fmt(k.created_at) }}</p>
          </div>
          <button class="btn-secondary text-xs text-rose-600" @click="revoke(k)">Revocar</button>
        </div>
      </div>
    </div>

    <p class="mt-6 text-xs text-slate-400">
      Base de la API pública: <code class="rounded bg-slate-100 px-1 dark:bg-slate-800">/api/public/v1</code> ·
      Autentícate con <code class="rounded bg-slate-100 px-1 dark:bg-slate-800">Authorization: Bearer &lt;api-key&gt;</code> ·
      Endpoint MCP: <code class="rounded bg-slate-100 px-1 dark:bg-slate-800">POST /api/public/v1/mcp</code>
    </p>

    <ModalDialog :open="modalOpen" title="Nueva API key" @close="modalOpen = false">
      <!-- Secreto recién creado -->
      <div v-if="createdKey" class="space-y-4">
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/30">
          <p class="mb-2 text-sm font-medium text-amber-800 dark:text-amber-200">
            Copia esta clave ahora: no volverá a mostrarse.
          </p>
          <div class="flex items-center gap-2">
            <code class="flex-1 break-all rounded bg-white px-2 py-1.5 font-mono text-xs dark:bg-slate-900">{{ createdKey }}</code>
            <button class="btn-secondary text-xs" @click="copyKey"><AppIcon name="copy" :size="14" /> Copiar</button>
          </div>
        </div>
        <div class="flex justify-end">
          <button class="btn-primary text-sm" @click="modalOpen = false">Entendido</button>
        </div>
      </div>

      <!-- Formulario -->
      <div v-else class="space-y-4">
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">Nombre</label>
          <input v-model="form.name" class="input" placeholder="Ej. Integración CRM" />
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">Permisos (scopes)</label>
          <div class="space-y-1.5">
            <label v-for="(label, scope) in scopeCatalog" :key="scope" class="flex cursor-pointer items-center gap-2 text-sm">
              <input
                type="checkbox"
                class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                :value="scope"
                v-model="form.scopes"
              />
              <span class="font-mono text-xs text-slate-500">{{ scope }}</span>
              <span class="text-slate-600 dark:text-slate-300">— {{ label }}</span>
            </label>
          </div>
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">Expiración (opcional)</label>
          <input v-model="form.expires_at" type="date" class="input" />
        </div>
        <div class="flex justify-end gap-2">
          <button class="btn-secondary text-sm" @click="modalOpen = false">Cancelar</button>
          <button class="btn-primary text-sm" :disabled="saving" @click="create">{{ saving ? 'Creando…' : 'Crear' }}</button>
        </div>
      </div>
    </ModalDialog>
  </div>
</template>
