<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage } from '@/utils/errors'
import PageHeader from '@/components/ui/PageHeader.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import StatCard from '@/components/StatCard.vue'
import ManualConnectionDialog from '@/components/social/ManualConnectionDialog.vue'
import AppIcon from '@/components/AppIcon.vue'

interface Connection {
  id: string
  provider: string
  account_name: string | null
  status_label: string
  needs_attention: boolean
  destinations: unknown[]
}
interface Provider { key: string; name: string }
interface BrandBlock { id: string; name: string; connections: Connection[] }

const auth = useAuthStore()
const toasts = useToastStore()
const route = useRoute()

const blocks = ref<BrandBlock[]>([])
const providers = ref<Provider[]>([])
const loading = ref(true)
const failed = ref(false)

const totalConnections = computed(() => blocks.value.reduce((n, b) => n + b.connections.length, 0))
const brandsConnected = computed(() => blocks.value.filter((b) => b.connections.length > 0).length)

const manualOpen = ref(false)
const manualBrandId = ref<string | null>(null)
function openManual(brandId: string): void {
  manualBrandId.value = brandId
  manualOpen.value = true
}

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  const brands = auth.brands
  if (brands.length === 0) {
    blocks.value = []
    loading.value = false
    return
  }
  try {
    // Los proveedores habilitados son de plataforma (iguales para todas las marcas).
    const providersResp = await http.get(`/brands/${brands[0].id}/social/providers`)
    providers.value = providersResp.data.data

    blocks.value = await Promise.all(
      brands.map(async (b) => {
        const { data } = await http.get(`/brands/${b.id}/social/connections`)
        return { id: b.id, name: b.name, connections: data.data as Connection[] }
      }),
    )
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

async function connect(brandId: string, providerKey: string): Promise<void> {
  try {
    const { data } = await http.post(`/brands/${brandId}/social/connections/${providerKey}/authorize`)
    window.location.href = data.data.authorize_url
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function disconnect(block: BrandBlock, id: string): Promise<void> {
  if (!confirm('¿Eliminar esta conexión social?')) return
  try {
    await http.delete(`/social/connections/${id}`)
    block.connections = block.connections.filter((c) => c.id !== id)
    toasts.success('Conexión eliminada.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

onMounted(() => {
  const status = route.query.social as string | undefined
  if (status === 'connected') toasts.success('Cuenta conectada.')
  else if (status === 'denied') toasts.error('Autorización cancelada.')
  else if (status === 'invalid') toasts.error('El enlace de conexión expiró. Inténtalo de nuevo.')
  else if (status === 'error') toasts.error('No se pudo completar la conexión.')
  load()
})
</script>

<template>
  <div>
    <PageHeader title="Redes sociales" description="Todas las cuentas conectadas de tus marcas en un solo lugar." />

    <EmptyState
      v-if="!loading && !failed && auth.brands.length === 0"
      icon="brands"
      title="Crea una marca primero"
      description="Las conexiones sociales pertenecen a cada marca."
    >
      <template #action>
        <RouterLink to="/app/brands" class="btn-primary text-sm">Ir a marcas</RouterLink>
      </template>
    </EmptyState>

    <div v-else-if="loading" class="card p-6"><div class="skeleton h-40 w-full" /></div>
    <ErrorState v-else-if="failed" @retry="load" />

    <template v-else>
      <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <StatCard label="Marcas" :value="blocks.length" icon="brands" />
        <StatCard label="Con redes conectadas" :value="brandsConnected" icon="social" />
        <StatCard label="Cuentas conectadas" :value="totalConnections" icon="check" />
      </div>

      <div class="space-y-4">
        <div v-for="block in blocks" :key="block.id" class="card p-5">
          <div class="mb-3 flex items-center justify-between">
            <RouterLink
              :to="`/app/brands/${block.id}`"
              class="font-semibold text-slate-900 hover:text-brand-600 dark:text-white"
            >
              {{ block.name }}
            </RouterLink>
            <span class="text-xs text-slate-400">{{ block.connections.length }} conexión(es)</span>
          </div>

          <!-- Conexiones existentes -->
          <div v-if="block.connections.length" class="mb-4 space-y-2">
            <div
              v-for="c in block.connections"
              :key="c.id"
              class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-100 p-3 dark:border-slate-800"
            >
              <div class="flex items-center gap-3">
                <span class="grid h-9 w-9 place-items-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-950/50">
                  <AppIcon name="social" :size="18" />
                </span>
                <div>
                  <p class="text-sm font-medium capitalize text-slate-900 dark:text-white">
                    {{ c.provider }} <span class="text-slate-400">· {{ c.account_name }}</span>
                  </p>
                  <p class="text-xs" :class="c.needs_attention ? 'text-amber-600' : 'text-emerald-600'">
                    {{ c.status_label }}
                    <span v-if="c.destinations.length" class="text-slate-400">· {{ c.destinations.length }} destino(s)</span>
                  </p>
                </div>
              </div>
              <button
                v-if="auth.can('social_accounts.disconnect')"
                class="btn-ghost text-sm text-rose-600"
                @click="disconnect(block, c.id)"
              >
                Desconectar
              </button>
            </div>
          </div>
          <p v-else class="mb-4 text-sm text-slate-400">Sin cuentas conectadas.</p>

          <!-- Conectar -->
          <div v-if="auth.can('social_accounts.connect') && providers.length" class="flex flex-wrap items-center gap-2">
            <button
              v-for="p in providers"
              :key="p.key"
              class="btn-secondary text-sm"
              @click="connect(block.id, p.key)"
            >
              <AppIcon name="plus" :size="14" /> {{ p.name }}
            </button>
            <button class="btn-ghost text-sm" @click="openManual(block.id)">
              <AppIcon name="key" :size="14" /> Conexión manual
            </button>
          </div>
        </div>
      </div>
    </template>

    <ManualConnectionDialog
      :open="manualOpen"
      :brand-id="manualBrandId"
      :providers="providers"
      @close="manualOpen = false"
      @connected="load"
    />
  </div>
</template>
