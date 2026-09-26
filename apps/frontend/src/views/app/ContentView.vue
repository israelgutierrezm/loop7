<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useDebounceFn } from '@vueuse/core'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage, apiValidationErrors } from '@/utils/errors'
import { dateTime, relativeTime } from '@/utils/format'
import { useQueryAction } from '@/composables/useQueryAction'
import PageHeader from '@/components/ui/PageHeader.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import ModalDialog from '@/components/ui/ModalDialog.vue'
import StatusBadge, { type BadgeTone } from '@/components/ui/StatusBadge.vue'
import BrandPicker from '@/components/BrandPicker.vue'
import Spinner from '@/components/ui/Spinner.vue'
import AppIcon from '@/components/AppIcon.vue'
import ProviderIcon from '@/components/social/ProviderIcon.vue'

interface ContentRow {
  id: string
  title: string
  type_label: string
  status: string
  status_label: string
  scheduled_at: string | null
  created_at: string | null
  campaign: { id: string; name: string } | null
  variants: { provider: string }[]
}
interface CampaignOption { id: string; name: string; status: string }

const STATUS_OPTIONS = [
  { value: '', label: 'Todos los estados' },
  { value: 'draft', label: 'Borrador' },
  { value: 'in_review', label: 'En revisión' },
  { value: 'changes_requested', label: 'Cambios solicitados' },
  { value: 'approved', label: 'Aprobado' },
  { value: 'scheduled', label: 'Programado' },
  { value: 'published', label: 'Publicado' },
  { value: 'partial', label: 'Parcial' },
  { value: 'failed', label: 'Fallido' },
  { value: 'cancelled', label: 'Cancelado' },
]
const TONES: Record<string, BadgeTone> = {
  idea: 'neutral',
  draft: 'neutral',
  in_review: 'warning',
  changes_requested: 'danger',
  approved: 'success',
  scheduled: 'brand',
  publishing: 'info',
  published: 'success',
  partial: 'warning',
  failed: 'danger',
  cancelled: 'neutral',
  expired: 'neutral',
}

const auth = useAuthStore()
const toasts = useToastStore()
const route = useRoute()
const router = useRouter()

const queryString = (key: string): string => (typeof route.query[key] === 'string' ? (route.query[key] as string) : '')
const brandId = ref<string | null>(
  (auth.brands.some((b) => b.id === queryString('brand')) ? queryString('brand') : null) ?? auth.brands[0]?.id ?? null,
)
const status = ref(queryString('status'))
const campaign = ref(queryString('campaign'))
const search = ref('')

const items = ref<ContentRow[]>([])
const campaigns = ref<CampaignOption[]>([])
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)
const loading = ref(false)
const loadingMore = ref(false)
const failed = ref(false)

const showCreate = ref(false)
const creating = ref(false)
const form = reactive({ title: '', body: '', type: 'post', campaign: '' })
const errors = ref<Record<string, string[]>>({})

async function fetchPage(p: number): Promise<void> {
  if (!brandId.value) return
  const { data } = await http.get(`/brands/${brandId.value}/content`, {
    params: {
      page: p,
      per_page: 20,
      status: status.value || undefined,
      campaign: campaign.value || undefined,
      q: search.value.trim() || undefined,
    },
  })
  items.value = p === 1 ? data.data : [...items.value, ...data.data]
  page.value = data.meta.current_page ?? p
  lastPage.value = data.meta.last_page ?? p
  total.value = data.meta.total ?? items.value.length
}

async function loadCampaigns(): Promise<void> {
  if (!brandId.value || !auth.can('campaigns.view')) {
    campaigns.value = []
    return
  }
  try {
    const { data } = await http.get(`/brands/${brandId.value}/campaigns`)
    campaigns.value = data.data
  } catch {
    campaigns.value = []
  }
}

async function load(): Promise<void> {
  if (!brandId.value) return
  loading.value = true
  failed.value = false
  try {
    await Promise.all([fetchPage(1), loadCampaigns()])
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

async function reload(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    await fetchPage(1)
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

async function more(): Promise<void> {
  loadingMore.value = true
  try {
    await fetchPage(page.value + 1)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    loadingMore.value = false
  }
}

function openCreate(): void {
  Object.assign(form, { title: '', body: '', type: 'post', campaign: campaign.value && campaign.value !== 'none' ? campaign.value : '' })
  errors.value = {}
  showCreate.value = true
}

async function create(): Promise<void> {
  if (!brandId.value) return
  creating.value = true
  errors.value = {}
  try {
    const { data } = await http.post(`/brands/${brandId.value}/content`, {
      title: form.title,
      body: form.body || null,
      type: form.type,
      campaign: form.campaign || null,
    })
    showCreate.value = false
    toasts.success('Contenido creado.')
    router.push(`/app/content/${data.data.id}`)
  } catch (e) {
    errors.value = apiValidationErrors(e)
    if (!Object.keys(errors.value).length) toasts.error(apiErrorMessage(e))
  } finally {
    creating.value = false
  }
}

function providers(item: ContentRow): string[] {
  return [...new Set(item.variants.map((v) => v.provider))]
}

const hasFilters = () => Boolean(status.value || campaign.value || search.value.trim())
const debouncedReload = useDebounceFn(reload, 350)

watch(brandId, () => {
  campaign.value = ''
  load()
})
watch([status, campaign], reload)
watch(search, () => debouncedReload())
onMounted(load)
// Buscador de comandos → «Crear contenido».
useQueryAction('crear', () => {
  if (auth.can('content.create') && brandId.value) openCreate()
})
</script>

<template>
  <div>
    <PageHeader title="Contenido" description="Crea, revisa y organiza tus publicaciones.">
      <template #actions>
        <BrandPicker v-model="brandId" />
        <button v-if="auth.can('content.create') && brandId" class="btn-primary" @click="openCreate">
          <AppIcon name="plus" :size="18" /> Nuevo
        </button>
      </template>
    </PageHeader>

    <EmptyState v-if="!brandId" icon="brands" title="Crea una marca primero" description="El contenido pertenece a una marca." />

    <template v-else>
      <div class="mb-4 flex flex-wrap items-center gap-2">
        <div class="relative min-w-[12rem] flex-1">
          <AppIcon name="search" :size="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
          <label class="sr-only" for="content-search">Buscar por título</label>
          <input id="content-search" v-model="search" type="search" class="input pl-9" placeholder="Buscar por título…" />
        </div>
        <label class="sr-only" for="content-status">Estado</label>
        <select id="content-status" v-model="status" class="input w-auto">
          <option v-for="s in STATUS_OPTIONS" :key="s.value" :value="s.value">{{ s.label }}</option>
        </select>
        <template v-if="campaigns.length">
          <label class="sr-only" for="content-campaign">Campaña</label>
          <select id="content-campaign" v-model="campaign" class="input w-auto">
            <option value="">Todas las campañas</option>
            <option value="none">Sin campaña</option>
            <option v-for="c in campaigns" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
        </template>
      </div>

      <div v-if="loading" class="card p-6"><div v-for="n in 5" :key="n" class="skeleton my-2 h-10 w-full" /></div>
      <ErrorState v-else-if="failed" @retry="load" />
      <EmptyState
        v-else-if="items.length === 0"
        icon="content"
        :title="hasFilters() ? 'Nada coincide con estos filtros' : 'Sin contenido'"
        :description="hasFilters() ? 'Prueba con otro estado, campaña o búsqueda.' : 'Crea tu primera publicación.'"
      />

      <template v-else>
        <p class="mb-2 text-xs text-slate-500">{{ total }} contenido(s)</p>
        <div class="card divide-y divide-slate-100 dark:divide-slate-800">
          <RouterLink
            v-for="item in items"
            :key="item.id"
            :to="`/app/content/${item.id}`"
            class="flex flex-wrap items-center gap-3 px-5 py-4 transition hover:bg-slate-50 dark:hover:bg-slate-800/50"
          >
            <div class="min-w-0 flex-1">
              <p class="truncate font-medium text-slate-900 dark:text-white">{{ item.title }}</p>
              <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-400">
                <span>{{ item.type_label }}</span>
                <span v-if="item.campaign" class="rounded bg-slate-100 px-1.5 py-0.5 text-slate-500 dark:bg-slate-800">{{ item.campaign.name }}</span>
                <span v-if="item.scheduled_at">· {{ dateTime(item.scheduled_at) }}</span>
                <span v-else-if="item.created_at">· creado {{ relativeTime(item.created_at) }}</span>
              </p>
            </div>
            <span class="flex gap-1" :aria-label="`Redes: ${providers(item).join(', ') || 'ninguna'}`">
              <ProviderIcon v-for="p in providers(item)" :key="p" :provider="p" :size="22" />
            </span>
            <StatusBadge :tone="TONES[item.status] ?? 'neutral'" dot>{{ item.status_label }}</StatusBadge>
          </RouterLink>
        </div>
        <div v-if="page < lastPage" class="mt-4 text-center">
          <button class="btn-secondary text-sm" :disabled="loadingMore" @click="more">
            <Spinner v-if="loadingMore" :size="16" /> Cargar más
          </button>
        </div>
      </template>
    </template>

    <ModalDialog :open="showCreate" title="Nuevo contenido" @close="showCreate = false">
      <form class="space-y-4" @submit.prevent="create">
        <div>
          <label class="label" for="c-title">Título</label>
          <input id="c-title" v-model="form.title" type="text" required maxlength="255" class="input" />
          <p v-if="errors.title" class="mt-1 text-xs text-rose-600">{{ errors.title[0] }}</p>
        </div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label class="label" for="c-type">Tipo</label>
            <select id="c-type" v-model="form.type" class="input">
              <option value="post">Publicación</option>
              <option value="carousel">Carrusel</option>
              <option value="reel">Reel</option>
              <option value="story">Historia</option>
              <option value="video">Video</option>
              <option value="link">Enlace</option>
            </select>
          </div>
          <div v-if="campaigns.length">
            <label class="label" for="c-campaign">Campaña</label>
            <select id="c-campaign" v-model="form.campaign" class="input">
              <option value="">Sin campaña</option>
              <option v-for="c in campaigns.filter((x) => x.status !== 'archived')" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
          </div>
        </div>
        <div>
          <label class="label" for="c-body">Texto base</label>
          <textarea id="c-body" v-model="form.body" rows="4" class="input" placeholder="Luego podrás adaptarlo a cada red (también con IA)." />
        </div>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" class="btn-secondary" @click="showCreate = false">Cancelar</button>
          <button type="submit" class="btn-primary" :disabled="creating">
            <Spinner v-if="creating" :size="18" /> Crear
          </button>
        </div>
      </form>
    </ModalDialog>
  </div>
</template>
