<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import { useConfirmStore } from '@/stores/confirm'
import { apiErrorMessage } from '@/utils/errors'
import PageHeader from '@/components/ui/PageHeader.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import StatCard from '@/components/StatCard.vue'
import AppIcon from '@/components/AppIcon.vue'

interface UsageRow {
  id: string
  provider: string
  model: string | null
  modality: string
  operation: string
  units: number
  credits: number
  status: string
  byok: boolean
  brand: string | null
  created_at: string | null
}
interface Usage {
  period: string
  limit: number
  used: number
  remaining: number
  unlimited: boolean
  byok_available: boolean
  providers: { key: string; name: string }[]
  recent: UsageRow[]
}
interface KeyRow { provider: string; is_active: boolean; configured_credentials: string[] }
interface KeysInfo { available: boolean; providers: { key: string; name: string }[]; keys: KeyRow[] }

const auth = useAuthStore()
const toasts = useToastStore()
const confirmDialog = useConfirmStore()

const usage = ref<Usage | null>(null)
const keysInfo = ref<KeysInfo | null>(null)
const loading = ref(true)
const failed = ref(false)
const savingKey = ref(false)

const canManageKeys = computed(() => auth.can('ai.manage_own_keys'))
const newKey = reactive({ provider: '', api_key: '' })

const usedPct = computed(() => {
  if (!usage.value || usage.value.unlimited || usage.value.limit <= 0) return 0
  return Math.min(100, Math.round((usage.value.used / usage.value.limit) * 100))
})

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const requests: Promise<unknown>[] = [http.get('/ai/usage')]
    if (canManageKeys.value) requests.push(http.get('/ai/keys'))
    const [usageRes, keysRes] = await Promise.all(requests)
    usage.value = (usageRes as { data: { data: Usage } }).data.data
    if (keysRes) {
      keysInfo.value = (keysRes as { data: { data: KeysInfo } }).data.data
      newKey.provider = keysInfo.value.providers[0]?.key ?? ''
    }
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

async function saveKey(): Promise<void> {
  if (!newKey.provider || !newKey.api_key.trim()) {
    toasts.error('Elige proveedor e introduce la API key.')
    return
  }
  savingKey.value = true
  try {
    await http.post('/ai/keys', { provider: newKey.provider, credentials: { api_key: newKey.api_key } })
    newKey.api_key = ''
    toasts.success('Clave guardada de forma cifrada.')
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    savingKey.value = false
  }
}

async function removeKey(provider: string): Promise<void> {
  const ok = await confirmDialog.ask({ title: 'Eliminar clave propia', message: `Se eliminará tu clave de ${provider}. Las generaciones volverán a consumir créditos del plan.`, confirmText: 'Eliminar', danger: true })
  if (!ok) return
  try {
    await http.delete(`/ai/keys/${provider}`)
    toasts.success('Clave eliminada.')
    await load()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

const opLabels: Record<string, string> = {
  generate_post: 'Generar publicación',
  generate_ideas: 'Generar ideas',
  improve_text: 'Mejorar texto',
  adapt_variant: 'Adaptar por red',
  suggest_reply: 'Sugerir respuesta',
  generate_image: 'Generar imagen',
  index_document: 'Indexar documento',
}

function formatDate(value: string | null): string {
  if (!value) return '—'
  return new Date(value).toLocaleString('es', { dateStyle: 'short', timeStyle: 'short' })
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader title="Asistente IA" description="Créditos, proveedores y claves propias de tu organización." />

    <div v-if="loading" class="card p-6"><div class="skeleton h-40 w-full" /></div>
    <ErrorState v-else-if="failed" @retry="load" />

    <template v-else-if="usage">
      <!-- Créditos del periodo -->
      <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <StatCard
          label="Créditos del mes"
          :value="usage.unlimited ? 'Ilimitado' : usage.limit"
          icon="ai"
          :hint="`Periodo ${usage.period}`"
        />
        <StatCard label="Usados" :value="usage.used" icon="analytics" />
        <StatCard
          label="Disponibles"
          :value="usage.unlimited ? '∞' : usage.remaining"
          icon="sparkles"
        />
      </div>

      <div v-if="!usage.unlimited" class="card mb-6 p-5">
        <div class="mb-2 flex items-center justify-between text-sm">
          <span class="font-medium text-slate-700 dark:text-slate-200">Consumo del periodo</span>
          <span class="text-slate-500">{{ usage.used }} / {{ usage.limit }}</span>
        </div>
        <div class="h-2.5 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
          <div
            class="h-full rounded-full transition-all"
            :class="usedPct >= 90 ? 'bg-rose-500' : usedPct >= 70 ? 'bg-amber-500' : 'bg-brand-500'"
            :style="{ width: usedPct + '%' }"
          />
        </div>
        <p v-if="usedPct >= 90" class="mt-2 text-xs text-rose-600 dark:text-rose-400">
          Estás cerca de agotar tus créditos. Amplía tu plan o añade créditos adicionales.
        </p>
      </div>

      <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <!-- BYOK -->
        <div v-if="canManageKeys" class="card p-6">
          <h3 class="mb-1 font-semibold text-slate-900 dark:text-white">Claves propias (BYOK)</h3>
          <p class="mb-4 text-sm text-slate-500">
            Usa tus propias claves de proveedor. No consumen créditos de la plataforma.
          </p>

          <div v-if="!keysInfo?.available" class="rounded-lg bg-amber-50 p-4 text-sm text-amber-700 dark:bg-amber-950/40 dark:text-amber-300">
            Tu plan actual no incluye claves propias (BYOK). Mejora tu plan para habilitarlo.
          </div>

          <template v-else>
            <ul v-if="keysInfo.keys.length" class="mb-4 space-y-2">
              <li
                v-for="k in keysInfo.keys"
                :key="k.provider"
                class="flex items-center justify-between rounded-lg border border-slate-100 px-3 py-2 dark:border-slate-800"
              >
                <span class="text-sm">
                  <span class="font-medium capitalize text-slate-800 dark:text-slate-100">{{ k.provider }}</span>
                  <span class="ml-2 text-xs text-slate-400">{{ k.configured_credentials.join(', ') }} · ••••</span>
                </span>
                <button class="text-rose-500 hover:text-rose-600" @click="removeKey(k.provider)">
                  <AppIcon name="close" :size="16" />
                </button>
              </li>
            </ul>
            <p v-else class="mb-4 text-sm text-slate-400">Sin claves configuradas.</p>

            <form class="space-y-3" @submit.prevent="saveKey">
              <select v-model="newKey.provider" class="input">
                <option v-for="p in keysInfo.providers" :key="p.key" :value="p.key">{{ p.name }}</option>
              </select>
              <input
                v-model="newKey.api_key"
                type="password"
                class="input"
                placeholder="API key"
                autocomplete="off"
              />
              <button type="submit" class="btn-primary text-sm" :disabled="savingKey">Guardar clave</button>
            </form>
          </template>
        </div>

        <!-- Uso reciente -->
        <div class="card p-6" :class="canManageKeys ? '' : 'lg:col-span-2'">
          <h3 class="mb-4 font-semibold text-slate-900 dark:text-white">Actividad reciente</h3>
          <div v-if="usage.recent.length === 0" class="text-sm text-slate-400">Sin actividad todavía.</div>
          <ul v-else class="divide-y divide-slate-100 dark:divide-slate-800">
            <li v-for="row in usage.recent" :key="row.id" class="flex items-center justify-between gap-3 py-2.5 text-sm">
              <div class="min-w-0">
                <p class="truncate font-medium text-slate-800 dark:text-slate-100">
                  {{ opLabels[row.operation] ?? row.operation }}
                  <span v-if="row.byok" class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">BYOK</span>
                </p>
                <p class="truncate text-xs text-slate-400">
                  {{ row.brand || 'Marca' }} · {{ row.provider }} · {{ formatDate(row.created_at) }}
                </p>
              </div>
              <div class="shrink-0 text-right">
                <span
                  class="rounded-full px-2 py-0.5 text-xs font-semibold"
                  :class="row.status === 'succeeded' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300' : 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300'"
                >
                  {{ row.credits }} créd.
                </span>
              </div>
            </li>
          </ul>
        </div>
      </div>
    </template>
  </div>
</template>
