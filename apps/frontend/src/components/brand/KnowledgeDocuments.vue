<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import http from '@/services/http'
import { useToastStore } from '@/stores/toasts'
import { useConfirmStore } from '@/stores/confirm'
import { apiErrorMessage, apiValidationErrors } from '@/utils/errors'
import { bytes, relativeTime } from '@/utils/format'
import StatusBadge, { type BadgeTone } from '@/components/ui/StatusBadge.vue'
import Spinner from '@/components/ui/Spinner.vue'
import AppIcon from '@/components/AppIcon.vue'

/**
 * Documentos del Brand Brain (RAG): la IA busca en ellos los fragmentos
 * relevantes para cada petición. Subir, ver su indexado, reindexar, descargar,
 * eliminar y probar qué encontraría la IA para una pregunta.
 */
interface KnowledgeDocument {
  id: string
  title: string
  original_name: string
  extension: string
  size_bytes: number
  status: 'pending' | 'processing' | 'ready' | 'failed'
  status_label: string
  error: string | null
  chunks_count: number
  characters: number
  truncated: boolean
  semantic: boolean
  uploaded_by: string | null
  indexed_at: string | null
  created_at: string | null
}
interface SearchResult {
  document: string
  position: number
  content: string
  score: number
  method: 'semantic' | 'keyword'
}

const props = defineProps<{ brandId: string; canEdit: boolean }>()

const toasts = useToastStore()
const confirmDialog = useConfirmStore()

const documents = ref<KnowledgeDocument[]>([])
const used = ref(0)
const limit = ref(0)
const semantic = ref(false)
const maxSizeMb = ref(10)
const extensions = ref<string[]>(['pdf', 'docx', 'txt', 'md', 'csv'])
const loading = ref(true)
const uploading = ref(false)
const busyId = ref<string | null>(null)
let poll: ReturnType<typeof setInterval> | null = null

const query = ref('')
const searching = ref(false)
const results = ref<SearchResult[] | null>(null)

const accept = computed(() => extensions.value.map((e) => `.${e}`).join(','))
const unlimited = computed(() => limit.value === -1)
const atLimit = computed(() => !unlimited.value && used.value >= limit.value)
const tones: Record<KnowledgeDocument['status'], BadgeTone> = { pending: 'neutral', processing: 'info', ready: 'success', failed: 'danger' }

async function load(silent = false): Promise<void> {
  if (!silent) loading.value = true
  try {
    const { data } = await http.get(`/brands/${props.brandId}/documents`)
    documents.value = data.data.documents
    used.value = data.data.used
    limit.value = data.data.limit
    semantic.value = Boolean(data.data.semantic)
    maxSizeMb.value = data.data.max_size_mb
    extensions.value = data.data.extensions
  } catch (e) {
    if (!silent) toasts.error(apiErrorMessage(e))
  } finally {
    loading.value = false
    syncPolling()
  }
}

// Mientras algo se esté indexando, se refresca cada 3 s.
function syncPolling(): void {
  const pending = documents.value.some((d) => d.status === 'pending' || d.status === 'processing')
  if (pending && !poll) poll = setInterval(() => void load(true), 3000)
  if (!pending && poll) {
    clearInterval(poll)
    poll = null
  }
}

async function upload(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) return
  if (file.size > maxSizeMb.value * 1024 * 1024) {
    toasts.error(`El archivo supera los ${maxSizeMb.value} MB.`)
    return
  }
  uploading.value = true
  const form = new FormData()
  form.append('file', file)
  try {
    const { data } = await http.post(`/brands/${props.brandId}/documents`, form)
    documents.value = [data.data, ...documents.value.filter((d) => d.id !== data.data.id)]
    used.value++
    toasts.success(data.message ?? 'Documento subido.')
    await load(true)
  } catch (e) {
    toasts.error(apiValidationErrors(e).file?.[0] ?? apiErrorMessage(e))
  } finally {
    uploading.value = false
  }
}

async function reindex(doc: KnowledgeDocument): Promise<void> {
  busyId.value = doc.id
  try {
    await http.post(`/brands/${props.brandId}/documents/${doc.id}/reindex`)
    await load(true)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busyId.value = null
  }
}

async function remove(doc: KnowledgeDocument): Promise<void> {
  const ok = await confirmDialog.ask({
    title: `Eliminar «${doc.title}»`,
    message: 'Se borran el archivo y su contenido indexado: la IA dejará de usarlo.',
    confirmText: 'Eliminar',
    danger: true,
  })
  if (!ok) return
  busyId.value = doc.id
  try {
    await http.delete(`/brands/${props.brandId}/documents/${doc.id}`)
    documents.value = documents.value.filter((d) => d.id !== doc.id)
    used.value = Math.max(0, used.value - 1)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busyId.value = null
  }
}

// Descarga con la sesión y la organización actuales (un enlace directo no lleva la cabecera de organización).
async function download(doc: KnowledgeDocument): Promise<void> {
  busyId.value = doc.id
  try {
    const response = await http.get(`/brands/${props.brandId}/documents/${doc.id}/download`, { responseType: 'blob' })
    const url = URL.createObjectURL(response.data as Blob)
    const link = document.createElement('a')
    link.href = url
    link.download = doc.original_name
    link.click()
    URL.revokeObjectURL(url)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busyId.value = null
  }
}

async function search(): Promise<void> {
  if (query.value.trim().length < 2) return
  searching.value = true
  try {
    const { data } = await http.get(`/brands/${props.brandId}/documents/search`, { params: { q: query.value.trim() } })
    results.value = data.data
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    searching.value = false
  }
}

onMounted(() => void load())
onBeforeUnmount(() => {
  if (poll) clearInterval(poll)
})
</script>

<template>
  <section class="card p-5" aria-labelledby="knowledge-docs-title">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-0">
        <h3 id="knowledge-docs-title" class="font-semibold text-slate-900 dark:text-white">Documentos</h3>
        <p class="mt-0.5 text-sm text-slate-500">
          Manuales, catálogos, políticas o guías de estilo: la IA busca en ellos lo relevante para cada texto que genera.
        </p>
        <p class="mt-1 flex items-center gap-1.5 text-xs" :class="semantic ? 'text-emerald-700 dark:text-emerald-400' : 'text-slate-500'">
          <AppIcon :name="semantic ? 'sparkles' : 'search'" :size="12" />
          {{ semantic ? 'Búsqueda semántica activa: encuentra ideas aunque se digan con otras palabras.' : 'Búsqueda por palabras clave. Con OpenAI configurado (o tu clave propia) la búsqueda es semántica.' }}
        </p>
      </div>
      <div class="flex shrink-0 flex-col items-end gap-1">
        <label v-if="canEdit" class="btn-primary cursor-pointer text-sm" :class="uploading || atLimit ? 'pointer-events-none opacity-60' : ''">
          <Spinner v-if="uploading" :size="16" />
          <AppIcon v-else name="upload" :size="16" />
          Subir documento
          <input type="file" class="sr-only" :accept="accept" :disabled="uploading || atLimit" @change="upload" />
        </label>
        <span class="text-xs text-slate-400">
          {{ used }} {{ unlimited ? 'documentos' : `de ${limit} documentos del plan` }} · {{ extensions.join(', ').toUpperCase() }} · máx. {{ maxSizeMb }} MB
        </span>
      </div>
    </div>

    <div v-if="loading" class="skeleton mt-4 h-20 w-full" />

    <p v-else-if="documents.length === 0" class="mt-4 rounded-lg bg-slate-50 px-4 py-6 text-center text-sm text-slate-500 dark:bg-slate-800/60">
      Aún no hay documentos. {{ canEdit ? 'Sube el primero para que la IA escriba con datos reales de la marca.' : '' }}
    </p>

    <ul v-else class="mt-4 divide-y divide-slate-100 dark:divide-slate-800">
      <li v-for="doc in documents" :key="doc.id" class="flex flex-wrap items-center gap-3 py-3">
        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-slate-100 text-slate-500 dark:bg-slate-800">
          <AppIcon name="document" :size="18" />
        </span>
        <div class="min-w-0 flex-1">
          <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-slate-900 dark:text-white">
            <span class="truncate">{{ doc.title }}</span>
            <StatusBadge :tone="tones[doc.status]" :dot="doc.status === 'processing' || doc.status === 'pending'">{{ doc.status_label }}</StatusBadge>
            <StatusBadge v-if="doc.status === 'ready' && doc.semantic" tone="brand">Semántico</StatusBadge>
          </p>
          <p class="text-xs text-slate-400">
            {{ doc.extension.toUpperCase() }} · {{ bytes(doc.size_bytes) }}
            <template v-if="doc.status === 'ready'"> · {{ doc.chunks_count }} fragmentos</template>
            · {{ doc.uploaded_by ?? 'Alguien' }}, {{ relativeTime(doc.created_at) }}
          </p>
          <p v-if="doc.error" class="mt-0.5 text-xs" :class="doc.status === 'failed' ? 'text-rose-600' : 'text-amber-700 dark:text-amber-400'">{{ doc.error }}</p>
          <p v-else-if="doc.truncated" class="mt-0.5 text-xs text-amber-700 dark:text-amber-400">Documento muy largo: se indexó su primera parte.</p>
        </div>
        <div class="flex shrink-0 gap-1">
          <button type="button" class="btn-ghost px-2 py-1 text-xs" :disabled="busyId === doc.id" :aria-label="`Descargar ${doc.title}`" @click="download(doc)">
            Descargar
          </button>
          <button
            v-if="canEdit && (doc.status === 'ready' || doc.status === 'failed')"
            type="button"
            class="btn-ghost px-2 py-1 text-xs"
            :disabled="busyId === doc.id"
            @click="reindex(doc)"
          >
            Reindexar
          </button>
          <button
            v-if="canEdit"
            type="button"
            class="btn-ghost px-2 py-1 text-rose-600"
            :disabled="busyId === doc.id"
            :aria-label="`Eliminar ${doc.title}`"
            @click="remove(doc)"
          >
            <AppIcon name="close" :size="16" />
          </button>
        </div>
      </li>
    </ul>

    <form v-if="documents.some((d) => d.status === 'ready')" class="mt-5 border-t border-slate-100 pt-4 dark:border-slate-800" @submit.prevent="search">
      <label class="label" for="knowledge-test">Prueba qué encontraría la IA</label>
      <div class="flex gap-2">
        <input id="knowledge-test" v-model="query" class="input flex-1" maxlength="300" placeholder="¿Abren los domingos? ¿Cuánto cuesta el envío?" />
        <button type="submit" class="btn-secondary" :disabled="searching || query.trim().length < 2">
          <Spinner v-if="searching" :size="16" /> Buscar
        </button>
      </div>
      <p v-if="results && results.length === 0" class="mt-3 text-sm text-slate-500">
        No hay fragmentos relevantes: la IA respondería sólo con el resto del Brand Brain.
      </p>
      <ol v-else-if="results" class="mt-3 space-y-2">
        <li v-for="(r, i) in results" :key="`${r.document}-${r.position}-${i}`" class="rounded-lg bg-slate-50 p-3 text-sm dark:bg-slate-800/60">
          <p class="mb-1 flex items-center gap-2 text-xs font-medium text-slate-500">
            <AppIcon name="document" :size="12" /> {{ r.document }}
            <span class="ml-auto">{{ r.method === 'semantic' ? 'Semántico' : 'Palabras clave' }} · {{ Math.round(r.score * 100) }}%</span>
          </p>
          <p class="text-slate-700 dark:text-slate-200">{{ r.content }}</p>
        </li>
      </ol>
    </form>
  </section>
</template>
