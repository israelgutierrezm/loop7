<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage, apiValidationErrors } from '@/utils/errors'
import PageHeader from '@/components/ui/PageHeader.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import ModalDialog from '@/components/ui/ModalDialog.vue'
import BrandPicker from '@/components/BrandPicker.vue'
import Spinner from '@/components/ui/Spinner.vue'
import AppIcon from '@/components/AppIcon.vue'

interface ContentRow {
  id: string
  title: string
  status: string
  status_label: string
  scheduled_at: string | null
  variants: { provider: string }[]
}

const STATUS_CLASSES: Record<string, string> = {
  draft: 'bg-slate-100 text-slate-600',
  in_review: 'bg-amber-100 text-amber-700',
  changes_requested: 'bg-rose-100 text-rose-700',
  approved: 'bg-emerald-100 text-emerald-700',
  scheduled: 'bg-brand-100 text-brand-700',
  published: 'bg-emerald-100 text-emerald-700',
}

const auth = useAuthStore()
const toasts = useToastStore()
const router = useRouter()

const brandId = ref<string | null>(auth.brands[0]?.id ?? null)
const items = ref<ContentRow[]>([])
const loading = ref(false)
const failed = ref(false)

const showCreate = ref(false)
const creating = ref(false)
const form = reactive({ title: '', body: '', type: 'post' })
const errors = ref<Record<string, string[]>>({})

async function load(): Promise<void> {
  if (!brandId.value) return
  loading.value = true
  failed.value = false
  try {
    const { data } = await http.get(`/brands/${brandId.value}/content`)
    items.value = data.data
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

async function create(): Promise<void> {
  if (!brandId.value) return
  creating.value = true
  errors.value = {}
  try {
    const { data } = await http.post(`/brands/${brandId.value}/content`, { ...form })
    showCreate.value = false
    form.title = ''
    form.body = ''
    toasts.success('Contenido creado.')
    router.push(`/app/content/${data.data.id}`)
  } catch (e) {
    errors.value = apiValidationErrors(e)
    if (!Object.keys(errors.value).length) toasts.error(apiErrorMessage(e))
  } finally {
    creating.value = false
  }
}

watch(brandId, load)
onMounted(load)
</script>

<template>
  <div>
    <PageHeader title="Contenido" description="Crea y gestiona tus publicaciones.">
      <template #actions>
        <BrandPicker v-model="brandId" />
        <button v-if="auth.can('content.create') && brandId" class="btn-primary" @click="showCreate = true">
          <AppIcon name="plus" :size="18" /> Nuevo
        </button>
      </template>
    </PageHeader>

    <EmptyState v-if="!brandId" icon="brands" title="Crea una marca primero" description="El contenido pertenece a una marca." />
    <div v-else-if="loading" class="card p-6"><div v-for="n in 5" :key="n" class="skeleton my-2 h-10 w-full" /></div>
    <ErrorState v-else-if="failed" @retry="load" />
    <EmptyState v-else-if="items.length === 0" icon="content" title="Sin contenido" description="Crea tu primera publicación." />

    <div v-else class="card divide-y divide-slate-100 dark:divide-slate-800">
      <RouterLink
        v-for="item in items"
        :key="item.id"
        :to="`/app/content/${item.id}`"
        class="flex items-center gap-3 px-5 py-4 transition hover:bg-slate-50 dark:hover:bg-slate-800/50"
      >
        <div class="min-w-0 flex-1">
          <p class="truncate font-medium text-slate-900 dark:text-white">{{ item.title }}</p>
          <p class="text-xs text-slate-400">
            {{ item.variants.length }} variante(s)
            <span v-if="item.scheduled_at"> · {{ new Date(item.scheduled_at).toLocaleString('es') }}</span>
          </p>
        </div>
        <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="STATUS_CLASSES[item.status] ?? 'bg-slate-100 text-slate-600'">
          {{ item.status_label }}
        </span>
      </RouterLink>
    </div>

    <ModalDialog :open="showCreate" title="Nuevo contenido" @close="showCreate = false">
      <form class="space-y-4" @submit.prevent="create">
        <div>
          <label class="label" for="c-title">Título</label>
          <input id="c-title" v-model="form.title" type="text" required class="input" />
          <p v-if="errors.title" class="mt-1 text-xs text-rose-600">{{ errors.title[0] }}</p>
        </div>
        <div>
          <label class="label" for="c-type">Tipo</label>
          <select id="c-type" v-model="form.type" class="input">
            <option value="post">Publicación</option>
            <option value="thread">Hilo</option>
            <option value="story">Historia</option>
            <option value="reel">Reel</option>
            <option value="carousel">Carrusel</option>
            <option value="video">Video</option>
          </select>
        </div>
        <div>
          <label class="label" for="c-body">Texto base</label>
          <textarea id="c-body" v-model="form.body" rows="4" class="input" />
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
