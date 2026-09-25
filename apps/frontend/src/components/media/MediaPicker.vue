<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useDebounceFn } from '@vueuse/core'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage } from '@/utils/errors'
import ModalDialog from '@/components/ui/ModalDialog.vue'
import Spinner from '@/components/ui/Spinner.vue'
import AppIcon from '@/components/AppIcon.vue'

export interface PickedMedia {
  id: string
  original_name: string
  is_image: boolean
  is_video: boolean
  url: string
}

const props = withDefaults(
  defineProps<{ open: boolean; brandId: string; selected: string[]; max?: number; acceptVideo?: boolean }>(),
  { max: 10, acceptVideo: true },
)
const emit = defineEmits<{ close: []; confirm: [media: PickedMedia[]] }>()

const auth = useAuthStore()
const toasts = useToastStore()

const items = ref<PickedMedia[]>([])
const chosen = ref<string[]>([])
// Todo lo visto en esta apertura: lo elegido se conserva aunque un filtro lo oculte.
const known = new Map<string, PickedMedia>()
const search = ref('')
const page = ref(1)
const lastPage = ref(1)
const loading = ref(false)
const loadingMore = ref(false)
const uploading = ref(false)
const generating = ref(false)
const showAi = ref(false)
const aiPrompt = ref('')
const aiSize = ref('1024x1024')

const canUpload = computed(() => auth.can('content.create'))
const canGenerate = computed(() => auth.can('ai.generate_image'))
const accept = computed(() => (props.acceptVideo ? 'image/jpeg,image/png,image/webp,image/gif,video/mp4' : 'image/jpeg,image/png,image/webp,image/gif'))

function remember(list: PickedMedia[]): void {
  list.forEach((m) => known.set(m.id, m))
}

async function fetchPage(p: number): Promise<void> {
  const { data } = await http.get(`/brands/${props.brandId}/media`, {
    params: { page: p, per_page: 30, type: props.acceptVideo ? 'visual' : 'image', q: search.value.trim() || undefined },
  })
  remember(data.data)
  items.value = p === 1 ? data.data : [...items.value, ...data.data]
  page.value = data.meta.current_page ?? p
  lastPage.value = data.meta.last_page ?? p
}

async function load(): Promise<void> {
  loading.value = true
  try {
    await fetchPage(1)
    // Los ya elegidos que no aparecen en la primera página se muestran primero.
    const missing = chosen.value.filter((id) => !known.has(id))
    if (missing.length > 0) {
      const { data } = await http.get(`/brands/${props.brandId}/media`, { params: { ids: missing.join(','), per_page: 30 } })
      remember(data.data)
      if (!search.value.trim()) items.value = [...data.data, ...items.value]
    }
  } catch (e) {
    toasts.error(apiErrorMessage(e))
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

const debouncedSearch = useDebounceFn(() => {
  loading.value = true
  fetchPage(1)
    .catch((e) => toasts.error(apiErrorMessage(e)))
    .finally(() => (loading.value = false))
}, 350)

function toggle(id: string): void {
  if (chosen.value.includes(id)) {
    chosen.value = chosen.value.filter((x) => x !== id)
  } else if (chosen.value.length < props.max) {
    chosen.value = [...chosen.value, id]
  } else {
    toasts.error(`Puedes elegir hasta ${props.max} archivos.`)
  }
}

async function upload(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  uploading.value = true
  const form = new FormData()
  form.append('file', file)
  try {
    const { data } = await http.post(`/brands/${props.brandId}/media`, form)
    remember([data.data])
    items.value.unshift(data.data)
    toggle(data.data.id)
    toasts.success('Archivo subido.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    uploading.value = false
    input.value = ''
  }
}

async function generate(): Promise<void> {
  if (!aiPrompt.value.trim()) {
    toasts.error('Describe la imagen que quieres.')
    return
  }
  generating.value = true
  try {
    const { data } = await http.post(`/brands/${props.brandId}/ai/image`, { prompt: aiPrompt.value, size: aiSize.value })
    const saved = data.data.media as PickedMedia[]
    if (saved.length === 0) {
      toasts.error('La imagen se generó pero no se pudo guardar en la biblioteca.')
      return
    }
    remember(saved)
    items.value.unshift(...saved)
    saved.forEach((m) => toggle(m.id))
    aiPrompt.value = ''
    showAi.value = false
    toasts.success(`Imagen generada (${data.data.credits} créditos) y guardada en la biblioteca.`)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    generating.value = false
  }
}

function confirm(): void {
  emit('confirm', chosen.value.map((id) => known.get(id)).filter((m): m is PickedMedia => m !== undefined))
}

watch(
  () => props.open,
  (open) => {
    if (!open) return
    chosen.value = [...props.selected]
    known.clear()
    search.value = ''
    load()
  },
)
watch(search, () => {
  if (props.open) debouncedSearch()
})
</script>

<template>
  <ModalDialog :open="open" title="Imágenes y videos" description="Elige de la biblioteca de la marca, sube un archivo o genera una imagen con IA." size="xl" @close="emit('close')">
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <label v-if="canUpload" class="btn-secondary cursor-pointer text-sm">
        <Spinner v-if="uploading" :size="16" />
        <AppIcon v-else name="plus" :size="16" /> Subir archivo
        <input type="file" class="sr-only" :accept="accept" :disabled="uploading" @change="upload" />
      </label>
      <button v-if="canGenerate" type="button" class="btn-secondary text-sm" @click="showAi = !showAi">
        <AppIcon name="sparkles" :size="16" /> Generar con IA
      </button>
      <label class="sr-only" for="picker-search">Buscar por nombre</label>
      <input id="picker-search" v-model="search" type="search" class="input w-44 py-1.5 text-sm" placeholder="Buscar…" />
      <span class="ml-auto text-xs text-slate-500">{{ chosen.length }} / {{ max }} seleccionados · el orden de selección es el orden de publicación</span>
    </div>

    <form v-if="showAi" class="mb-4 rounded-lg border border-brand-100 bg-brand-50/50 p-3 dark:border-brand-900 dark:bg-brand-950/30" @submit.prevent="generate">
      <label for="ai-img-prompt" class="label">¿Qué imagen necesitas?</label>
      <textarea id="ai-img-prompt" v-model="aiPrompt" rows="2" class="input" placeholder="Ej. Taza de café sobre escritorio de madera, luz natural, estilo minimalista" />
      <div class="mt-2 flex flex-wrap items-center justify-end gap-2">
        <label for="ai-img-size" class="sr-only">Formato</label>
        <select id="ai-img-size" v-model="aiSize" class="input w-auto">
          <option value="1024x1024">Cuadrada (feed)</option>
          <option value="1024x1792">Vertical (historias y reels)</option>
          <option value="1792x1024">Horizontal</option>
        </select>
        <button type="submit" class="btn-primary text-sm" :disabled="generating">
          <Spinner v-if="generating" :size="16" /> {{ generating ? 'Generando…' : 'Generar imagen' }}
        </button>
      </div>
    </form>

    <div v-if="loading" class="grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-5">
      <div v-for="n in 10" :key="n" class="skeleton aspect-square" />
    </div>
    <p v-else-if="items.length === 0" class="rounded-lg border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500 dark:border-slate-700">
      {{ search.trim() ? 'Ningún archivo coincide con la búsqueda.' : 'La biblioteca de esta marca está vacía. Sube un archivo o genera una imagen.' }}
    </p>
    <ul v-else class="grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-5">
      <li v-for="m in items" :key="m.id">
        <button
          type="button"
          class="group relative block aspect-square w-full overflow-hidden rounded-lg border-2 bg-slate-100 dark:bg-slate-800"
          :class="chosen.includes(m.id) ? 'border-brand-600 ring-2 ring-brand-500/30' : 'border-transparent hover:border-slate-300'"
          :aria-pressed="chosen.includes(m.id)"
          :aria-label="m.original_name"
          @click="toggle(m.id)"
        >
          <img v-if="m.is_image" :src="m.url" :alt="m.original_name" class="h-full w-full object-cover" loading="lazy" />
          <video v-else :src="m.url" class="h-full w-full object-cover" muted preload="metadata" />
          <span v-if="m.is_video" class="absolute left-1.5 top-1.5 rounded bg-slate-900/70 px-1.5 py-0.5 text-[10px] font-semibold text-white">VIDEO</span>
          <span
            v-if="chosen.includes(m.id)"
            class="absolute right-1.5 top-1.5 grid h-6 w-6 place-items-center rounded-full bg-brand-600 text-xs font-bold text-white"
          >
            {{ chosen.indexOf(m.id) + 1 }}
          </span>
        </button>
      </li>
    </ul>
    <div v-if="!loading && page < lastPage" class="mt-4 text-center">
      <button type="button" class="btn-secondary text-sm" :disabled="loadingMore" @click="more">
        <Spinner v-if="loadingMore" :size="16" /> Cargar más
      </button>
    </div>

    <template #footer>
      <button type="button" class="btn-secondary text-sm" @click="emit('close')">Cancelar</button>
      <button type="button" class="btn-primary text-sm" @click="confirm">Usar selección</button>
    </template>
  </ModalDialog>
</template>
