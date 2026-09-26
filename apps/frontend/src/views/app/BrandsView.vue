<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import type { Brand } from '@/types/models'
import { apiErrorMessage, apiValidationErrors } from '@/utils/errors'
import { useQueryAction } from '@/composables/useQueryAction'
import PageHeader from '@/components/ui/PageHeader.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import ModalDialog from '@/components/ui/ModalDialog.vue'
import AppIcon from '@/components/AppIcon.vue'
import BrandAvatar from '@/components/BrandAvatar.vue'
import Spinner from '@/components/ui/Spinner.vue'

const auth = useAuthStore()
const toasts = useToastStore()

const PER_PAGE = 24

const brands = ref<Brand[]>([])
const loading = ref(true)
const failed = ref(false)
const page = ref(1)
const lastPage = ref(1)
const loadingMore = ref(false)

const showCreate = ref(false)
const creating = ref(false)
const form = reactive({ name: '', website: '', description: '' })
const errors = ref<Record<string, string[]>>({})

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const { data } = await http.get('/brands', { params: { per_page: PER_PAGE } })
    brands.value = data.data
    page.value = 1
    lastPage.value = data.meta?.last_page ?? 1
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

async function loadMore(): Promise<void> {
  if (loadingMore.value || page.value >= lastPage.value) return
  loadingMore.value = true
  try {
    const { data } = await http.get('/brands', { params: { per_page: PER_PAGE, page: page.value + 1 } })
    const known = new Set(brands.value.map((b) => b.id))
    brands.value.push(...(data.data as Brand[]).filter((b) => !known.has(b.id)))
    page.value += 1
    lastPage.value = data.meta?.last_page ?? page.value
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    loadingMore.value = false
  }
}

async function create(): Promise<void> {
  creating.value = true
  errors.value = {}
  try {
    const { data } = await http.post('/brands', { ...form })
    brands.value.unshift(data.data)
    showCreate.value = false
    form.name = ''
    form.website = ''
    form.description = ''
    toasts.success('Marca creada.')
    await auth.loadContext()
  } catch (e) {
    errors.value = apiValidationErrors(e)
    if (!Object.keys(errors.value).length) toasts.error(apiErrorMessage(e))
  } finally {
    creating.value = false
  }
}

onMounted(load)
// Buscador de comandos → «Nueva marca».
useQueryAction('crear', () => {
  if (auth.can('brands.create')) showCreate.value = true
})
</script>

<template>
  <div>
    <PageHeader title="Marcas" description="Gestiona las marcas de tu organización.">
      <template #actions>
        <button v-if="auth.can('brands.create')" class="btn-primary" @click="showCreate = true">
          <AppIcon name="plus" :size="18" /> Nueva marca
        </button>
      </template>
    </PageHeader>

    <!-- Loading skeleton -->
    <div v-if="loading" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <div v-for="n in 3" :key="n" class="card p-5">
        <div class="skeleton h-10 w-10 rounded-xl" />
        <div class="skeleton mt-4 h-4 w-2/3" />
        <div class="skeleton mt-2 h-3 w-1/2" />
      </div>
    </div>

    <ErrorState v-else-if="failed" @retry="load" />

    <EmptyState
      v-else-if="brands.length === 0"
      icon="brands"
      title="Aún no tienes marcas"
      description="Crea tu primera marca para empezar a planificar y publicar contenido."
    >
      <template #action>
        <button v-if="auth.can('brands.create')" class="btn-primary" @click="showCreate = true">
          <AppIcon name="plus" :size="18" /> Crear marca
        </button>
      </template>
    </EmptyState>

    <div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <RouterLink
        v-for="brand in brands"
        :key="brand.id"
        :to="`/app/brands/${brand.id}`"
        class="card p-5 transition hover:shadow-md hover:ring-1 hover:ring-brand-200"
      >
        <div class="flex items-center gap-3">
          <BrandAvatar :name="brand.name" :logo-url="brand.logo?.url" :color="brand.primary_color" />
          <div class="min-w-0">
            <p class="truncate font-semibold text-slate-900 dark:text-white">{{ brand.name }}</p>
            <p class="truncate text-xs text-slate-400">{{ brand.website ?? 'Sin sitio web' }}</p>
          </div>
        </div>
        <p class="mt-3 line-clamp-2 text-sm text-slate-500">
          {{ brand.description ?? 'Sin descripción.' }}
        </p>
        <p class="mt-3 flex items-center gap-1 text-xs font-medium text-brand-600">
          Abrir Brand Brain <AppIcon name="chevron-right" :size="14" />
        </p>
      </RouterLink>
    </div>

    <div v-if="!loading && !failed && page < lastPage" class="mt-6 flex justify-center">
      <button type="button" class="btn-secondary" :disabled="loadingMore" @click="loadMore">
        <Spinner v-if="loadingMore" :size="16" /> Cargar más marcas
      </button>
    </div>

    <ModalDialog :open="showCreate" title="Nueva marca" @close="showCreate = false">
      <form class="space-y-4" @submit.prevent="create">
        <div>
          <label class="label" for="b-name">Nombre</label>
          <input id="b-name" v-model="form.name" type="text" required class="input" />
          <p v-if="errors.name" class="mt-1 text-xs text-rose-600">{{ errors.name[0] }}</p>
        </div>
        <div>
          <label class="label" for="b-web">Sitio web <span class="text-slate-400">(opcional)</span></label>
          <input id="b-web" v-model="form.website" type="url" class="input" placeholder="https://" />
          <p v-if="errors.website" class="mt-1 text-xs text-rose-600">{{ errors.website[0] }}</p>
        </div>
        <div>
          <label class="label" for="b-desc">Descripción <span class="text-slate-400">(opcional)</span></label>
          <textarea id="b-desc" v-model="form.description" rows="3" class="input" />
        </div>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" class="btn-secondary" @click="showCreate = false">Cancelar</button>
          <button type="submit" class="btn-primary" :disabled="creating">
            <Spinner v-if="creating" :size="18" /> Crear marca
          </button>
        </div>
      </form>
    </ModalDialog>
  </div>
</template>
