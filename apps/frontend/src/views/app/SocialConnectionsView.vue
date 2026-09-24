<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import PageHeader from '@/components/ui/PageHeader.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import StatCard from '@/components/StatCard.vue'
import BrandSocialPanel from '@/components/social/BrandSocialPanel.vue'
import type { SocialConnection, SocialProviderOption } from '@/types/models'

const auth = useAuthStore()
const toasts = useToastStore()
const route = useRoute()
const router = useRouter()

const providers = ref<SocialProviderOption[]>([])
const loading = ref(true)
const failed = ref(false)
const connectionsByBrand = reactive<Record<string, SocialConnection[]>>({})

const all = computed(() => Object.values(connectionsByBrand).flat())
const totalConnections = computed(() => all.value.length)
const needsAttention = computed(() => all.value.filter((c) => c.needs_attention).length)
const brandsConnected = computed(() => Object.values(connectionsByBrand).filter((list) => list.length > 0).length)

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  if (auth.brands.length === 0) {
    loading.value = false
    return
  }
  try {
    // Las redes habilitadas son de plataforma (iguales para todas las marcas).
    const { data } = await http.get(`/brands/${auth.brands[0].id}/social/providers`)
    providers.value = data.data
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

function handleReturn(): void {
  const status = route.query.social as string | undefined
  if (!status) return
  if (status === 'connected') toasts.success('Cuenta conectada.')
  else if (status === 'denied') toasts.error('Autorización cancelada.')
  else if (status === 'invalid') toasts.error('El enlace de conexión expiró. Inténtalo de nuevo.')
  else if (status === 'error') toasts.error('No se pudo completar la conexión.')
  else if (status === 'limit') toasts.error('Alcanzaste el número de cuentas sociales de tu plan. Amplía tu plan en Facturación.')
  router.replace({ query: {} })
}

onMounted(() => {
  handleReturn()
  load()
})
</script>

<template>
  <div>
    <PageHeader title="Redes sociales" description="Las cuentas conectadas de todas tus marcas en un solo lugar." />

    <EmptyState
      v-if="!loading && auth.brands.length === 0"
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
        <StatCard label="Marcas con redes" :value="`${brandsConnected} / ${auth.brands.length}`" icon="brands" />
        <StatCard label="Cuentas conectadas" :value="totalConnections" icon="social" />
        <StatCard
          label="Requieren atención"
          :value="needsAttention"
          icon="alert"
          :hint="needsAttention ? 'Reconéctalas para seguir publicando' : 'Todo en orden'"
        />
      </div>

      <div class="space-y-4">
        <section v-for="brand in auth.brands" :key="brand.id" class="card p-5" :aria-labelledby="`brand-${brand.id}`">
          <div class="mb-4 flex items-center justify-between gap-3">
            <RouterLink
              :id="`brand-${brand.id}`"
              :to="`/app/brands/${brand.id}`"
              class="font-semibold text-slate-900 hover:text-brand-600 dark:text-white"
            >
              {{ brand.name }}
            </RouterLink>
            <span class="text-xs text-slate-400">{{ connectionsByBrand[brand.id]?.length ?? 0 }} conexión(es)</span>
          </div>
          <BrandSocialPanel
            :brand-id="brand.id"
            :providers="providers"
            @changed="(list) => (connectionsByBrand[brand.id] = list)"
          />
        </section>
      </div>
    </template>
  </div>
</template>
