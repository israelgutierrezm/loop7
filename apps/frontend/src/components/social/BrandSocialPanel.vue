<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useConfirmStore } from '@/stores/confirm'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage } from '@/utils/errors'
import AppIcon from '@/components/AppIcon.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import ProviderIcon from '@/components/social/ProviderIcon.vue'
import ManualConnectionDialog from '@/components/social/ManualConnectionDialog.vue'
import type { SocialConnection, SocialProviderOption } from '@/types/models'

const props = defineProps<{ brandId: string; providers: SocialProviderOption[] }>()
const emit = defineEmits<{ changed: [connections: SocialConnection[]] }>()

const auth = useAuthStore()
const toasts = useToastStore()
const confirm = useConfirmStore()

const connections = ref<SocialConnection[]>([])
const loading = ref(true)
const failed = ref(false)
const busy = ref<string | null>(null)
const manualOpen = ref(false)

const hints: Record<string, string> = {
  instagram: 'Requiere una cuenta profesional de Instagram vinculada a una Página de Facebook.',
  facebook: 'Publica en las Páginas de Facebook que administras.',
}

function providerName(key: string): string {
  return props.providers.find((p) => p.key === key)?.name ?? key
}

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const { data } = await http.get(`/brands/${props.brandId}/social/connections`)
    connections.value = data.data
    emit('changed', connections.value)
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

async function connect(providerKey: string): Promise<void> {
  busy.value = providerKey
  try {
    const { data } = await http.post(`/brands/${props.brandId}/social/connections/${providerKey}/authorize`)
    // Redirige al flujo OAuth del proveedor (al volver, la cuenta queda conectada o actualizada).
    window.location.href = data.data.authorize_url
  } catch (e) {
    toasts.error(apiErrorMessage(e))
    busy.value = null
  }
}

async function disconnect(connection: SocialConnection): Promise<void> {
  const ok = await confirm.ask({
    title: 'Desconectar cuenta',
    message: `Se eliminará la conexión de ${providerName(connection.provider)} (${connection.account_name ?? 'cuenta'}). Las publicaciones programadas en sus destinos no se enviarán.`,
    confirmText: 'Desconectar',
    danger: true,
  })
  if (!ok) return
  busy.value = connection.id
  try {
    await http.delete(`/social/connections/${connection.id}`)
    connections.value = connections.value.filter((c) => c.id !== connection.id)
    emit('changed', connections.value)
    toasts.success('Conexión eliminada.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = null
  }
}

watch(() => props.brandId, load)
onMounted(load)
defineExpose({ reload: load })
</script>

<template>
  <div>
    <div v-if="loading" class="space-y-2">
      <div v-for="n in 2" :key="n" class="skeleton h-16 w-full" />
    </div>

    <div v-else-if="failed" class="flex items-center justify-between rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
      No se pudieron cargar las conexiones.
      <button type="button" class="btn-ghost text-sm" @click="load">Reintentar</button>
    </div>

    <template v-else>
      <ul v-if="connections.length" class="space-y-2">
        <li
          v-for="c in connections"
          :key="c.id"
          class="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3"
          :class="c.needs_attention ? 'border-amber-200 bg-amber-50/50 dark:border-amber-900/50 dark:bg-amber-950/20' : 'border-slate-200 dark:border-slate-800'"
        >
          <div class="flex min-w-0 items-center gap-3">
            <ProviderIcon :provider="c.provider" :size="36" />
            <div class="min-w-0">
              <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-slate-900 dark:text-white">
                {{ providerName(c.provider) }}
                <span class="truncate text-slate-400">· {{ c.account_name }}</span>
                <StatusBadge :tone="c.needs_attention ? 'warning' : 'success'" dot>{{ c.status_label }}</StatusBadge>
                <StatusBadge v-if="c.is_manual" tone="neutral">Manual</StatusBadge>
              </p>
              <p v-if="c.destinations.length" class="mt-0.5 truncate text-xs text-slate-500">
                Publica en: {{ c.destinations.map((d) => d.name).join(', ') }}
              </p>
              <p v-else class="mt-0.5 text-xs text-amber-600">Sin páginas/cuentas disponibles para publicar.</p>
              <p v-if="c.needs_attention" class="mt-0.5 text-xs text-amber-700 dark:text-amber-300">
                La red rechazó el acceso. Reconecta la cuenta para seguir publicando.
              </p>
            </div>
          </div>
          <div class="flex items-center gap-1">
            <button
              v-if="c.needs_attention && auth.can('social_accounts.connect') && providers.some((p) => p.key === c.provider)"
              type="button"
              class="btn-primary px-3 py-1.5 text-xs"
              :disabled="busy !== null"
              @click="connect(c.provider)"
            >
              <AppIcon name="refresh" :size="14" /> Reconectar
            </button>
            <button
              v-if="auth.can('social_accounts.disconnect')"
              type="button"
              class="btn-ghost px-3 py-1.5 text-xs text-rose-600"
              :disabled="busy !== null"
              @click="disconnect(c)"
            >
              Desconectar
            </button>
          </div>
        </li>
      </ul>
      <p v-else class="rounded-lg border border-dashed border-slate-300 p-4 text-center text-sm text-slate-500 dark:border-slate-700">
        Esta marca aún no tiene cuentas conectadas.
      </p>

      <div v-if="auth.can('social_accounts.connect')" class="mt-4">
        <p v-if="providers.length === 0" class="text-sm text-slate-500">
          No hay redes habilitadas. Un administrador de plataforma debe activarlas en Integraciones sociales.
        </p>
        <div v-else class="flex flex-wrap items-center gap-2">
          <button
            v-for="p in providers"
            :key="p.key"
            type="button"
            class="btn-secondary py-1.5 text-sm"
            :title="hints[p.key]"
            :disabled="busy !== null"
            @click="connect(p.key)"
          >
            <ProviderIcon :provider="p.key" :size="20" /> Conectar {{ p.name }}
          </button>
          <button type="button" class="btn-ghost py-1.5 text-sm" @click="manualOpen = true">
            <AppIcon name="key" :size="14" /> Conexión manual
          </button>
        </div>
      </div>
    </template>

    <ManualConnectionDialog
      :open="manualOpen"
      :brand-id="brandId"
      :providers="providers"
      @close="manualOpen = false"
      @connected="load"
    />
  </div>
</template>
