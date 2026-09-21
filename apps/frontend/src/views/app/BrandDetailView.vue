<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage } from '@/utils/errors'
import PageHeader from '@/components/ui/PageHeader.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Spinner from '@/components/ui/Spinner.vue'
import AppIcon from '@/components/AppIcon.vue'

interface Audience { id: string; name: string; description: string | null }
interface Product { id: string; name: string; description: string | null; price: string | null; url: string | null }
interface Service { id: string; name: string; description: string | null; url: string | null }
interface Knowledge { id: string; type: string; title: string; body: string | null; url: string | null }
interface MediaItem { id: string; original_name: string; url: string; is_image: boolean; size_bytes: number }

const route = useRoute()
const auth = useAuthStore()
const toasts = useToastStore()
const brandId = route.params.brand as string

const loading = ref(true)
const failed = ref(false)
const brandName = ref('')
const activeTab = ref<'identidad' | 'audiencias' | 'oferta' | 'conocimiento' | 'medios'>('identidad')

const canEdit = computed(() => auth.can('brands.update'))

const guidelines = reactive({
  voice_tone: '',
  cta: '',
  value_propositions: '',
  hashtags: '',
  preferred_vocabulary: '',
  prohibited_terms: '',
  notes: '',
})
const savingGuidelines = ref(false)

const audiences = ref<Audience[]>([])
const products = ref<Product[]>([])
const services = ref<Service[]>([])
const knowledge = ref<Knowledge[]>([])
const media = ref<MediaItem[]>([])

const newAudience = reactive({ name: '', description: '' })
const newProduct = reactive({ name: '', price: '', url: '' })
const newService = reactive({ name: '', url: '' })
const newKnowledge = reactive({ type: 'faq', title: '', body: '', url: '' })
const uploading = ref(false)

function linesToArray(text: string): string[] {
  return text.split('\n').map((l) => l.trim()).filter(Boolean)
}
function csvToArray(text: string): string[] {
  return text.split(/[,\n]/).map((l) => l.trim()).filter(Boolean)
}
function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const [brain, mediaResp] = await Promise.all([
      http.get(`/brands/${brandId}/brain`),
      http.get(`/brands/${brandId}/media`),
    ])
    const d = brain.data.data
    brandName.value = d.brand.name
    if (d.guidelines) {
      guidelines.voice_tone = d.guidelines.voice_tone ?? ''
      guidelines.cta = d.guidelines.cta ?? ''
      guidelines.value_propositions = (d.guidelines.value_propositions ?? []).join('\n')
      guidelines.hashtags = (d.guidelines.hashtags ?? []).join(', ')
      guidelines.preferred_vocabulary = (d.guidelines.preferred_vocabulary ?? []).join(', ')
      guidelines.prohibited_terms = (d.guidelines.prohibited_terms ?? []).join(', ')
      guidelines.notes = d.guidelines.notes ?? ''
    }
    audiences.value = d.audiences
    products.value = d.products
    services.value = d.services
    knowledge.value = d.knowledge
    media.value = mediaResp.data.data
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

async function saveGuidelines(): Promise<void> {
  savingGuidelines.value = true
  try {
    await http.put(`/brands/${brandId}/brain/guidelines`, {
      voice_tone: guidelines.voice_tone || null,
      cta: guidelines.cta || null,
      value_propositions: linesToArray(guidelines.value_propositions),
      hashtags: csvToArray(guidelines.hashtags),
      preferred_vocabulary: csvToArray(guidelines.preferred_vocabulary),
      prohibited_terms: csvToArray(guidelines.prohibited_terms),
      notes: guidelines.notes || null,
    })
    toasts.success('Identidad de marca guardada.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    savingGuidelines.value = false
  }
}

const lists: Record<string, { value: { id: string }[] }> = { audiences, products, services, knowledge }

async function addChild(kind: string, payload: object, resetFn: () => void): Promise<void> {
  try {
    const { data } = await http.post(`/brands/${brandId}/${kind}`, payload)
    lists[kind].value.unshift(data.data)
    resetFn()
    toasts.success('Añadido.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function deleteChild(kind: string, id: string): Promise<void> {
  try {
    await http.delete(`/brands/${brandId}/${kind}/${id}`)
    lists[kind].value = lists[kind].value.filter((i) => i.id !== id)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function uploadMedia(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  uploading.value = true
  const form = new FormData()
  form.append('file', file)
  try {
    const { data } = await http.post(`/brands/${brandId}/media`, form)
    media.value.unshift(data.data)
    toasts.success('Archivo subido.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    uploading.value = false
    input.value = ''
  }
}

async function deleteMedia(id: string): Promise<void> {
  try {
    await http.delete(`/media/${id}`)
    media.value = media.value.filter((m) => m.id !== id)
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

const tabs = [
  { key: 'identidad', label: 'Identidad' },
  { key: 'audiencias', label: 'Audiencias' },
  { key: 'oferta', label: 'Productos y servicios' },
  { key: 'conocimiento', label: 'Conocimiento' },
  { key: 'medios', label: 'Medios' },
] as const

onMounted(load)
</script>

<template>
  <div>
    <PageHeader :title="brandName || 'Marca'" description="Brand Brain: el contexto que alimenta la IA y el contenido.">
      <template #actions>
        <RouterLink to="/app/brands" class="btn-secondary text-sm">
          <AppIcon name="chevron-left" :size="16" /> Marcas
        </RouterLink>
      </template>
    </PageHeader>

    <div v-if="loading" class="card p-6"><div class="skeleton h-64 w-full" /></div>
    <ErrorState v-else-if="failed" @retry="load" />

    <template v-else>
      <div class="mb-5 flex gap-1 overflow-x-auto border-b border-slate-200 dark:border-slate-800">
        <button
          v-for="tab in tabs"
          :key="tab.key"
          class="whitespace-nowrap border-b-2 px-4 py-2.5 text-sm font-medium transition-colors"
          :class="activeTab === tab.key
            ? 'border-brand-600 text-brand-700 dark:text-brand-300'
            : 'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'"
          @click="activeTab = tab.key"
        >
          {{ tab.label }}
        </button>
      </div>

      <!-- Identidad -->
      <div v-show="activeTab === 'identidad'" class="card max-w-3xl p-6">
        <fieldset :disabled="!canEdit" class="space-y-4">
          <div>
            <label class="label">Voz y tono</label>
            <textarea v-model="guidelines.voice_tone" rows="2" class="input" placeholder="Cercano, profesional, con humor…" />
          </div>
          <div>
            <label class="label">Llamada a la acción (CTA)</label>
            <input v-model="guidelines.cta" class="input" placeholder="Empieza gratis hoy" />
          </div>
          <div>
            <label class="label">Propuestas de valor <span class="text-slate-400">(una por línea)</span></label>
            <textarea v-model="guidelines.value_propositions" rows="3" class="input" />
          </div>
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label class="label">Hashtags <span class="text-slate-400">(separados por coma)</span></label>
              <input v-model="guidelines.hashtags" class="input" placeholder="#marketing, #pymes" />
            </div>
            <div>
              <label class="label">Términos prohibidos</label>
              <input v-model="guidelines.prohibited_terms" class="input" placeholder="barato, gratis total" />
            </div>
          </div>
          <div>
            <label class="label">Vocabulario preferido</label>
            <input v-model="guidelines.preferred_vocabulary" class="input" />
          </div>
        </fieldset>
        <div v-if="canEdit" class="mt-6 flex justify-end">
          <button class="btn-primary" :disabled="savingGuidelines" @click="saveGuidelines">
            <Spinner v-if="savingGuidelines" :size="18" /> Guardar identidad
          </button>
        </div>
      </div>

      <!-- Audiencias -->
      <div v-show="activeTab === 'audiencias'" class="space-y-4">
        <div v-if="canEdit" class="card flex flex-wrap items-end gap-3 p-4">
          <div class="flex-1"><label class="label">Nombre</label><input v-model="newAudience.name" class="input" placeholder="PyMEs tecnológicas" /></div>
          <div class="flex-1"><label class="label">Descripción</label><input v-model="newAudience.description" class="input" /></div>
          <button class="btn-primary" @click="addChild('audiences', { ...newAudience }, () => { newAudience.name = ''; newAudience.description = '' })">Añadir</button>
        </div>
        <EmptyState v-if="audiences.length === 0" icon="team" title="Sin audiencias" description="Define a quién te diriges." />
        <div v-for="a in audiences" :key="a.id" class="card flex items-center justify-between p-4">
          <div><p class="font-medium text-slate-800 dark:text-slate-100">{{ a.name }}</p><p class="text-sm text-slate-500">{{ a.description }}</p></div>
          <button v-if="canEdit" class="btn-ghost text-rose-600" @click="deleteChild('audiences', a.id)"><AppIcon name="close" :size="18" /></button>
        </div>
      </div>

      <!-- Productos y servicios -->
      <div v-show="activeTab === 'oferta'" class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div class="space-y-3">
          <h3 class="font-semibold text-slate-900 dark:text-white">Productos</h3>
          <div v-if="canEdit" class="card flex flex-wrap items-end gap-2 p-4">
            <div class="flex-1"><input v-model="newProduct.name" class="input" placeholder="Nombre" /></div>
            <div class="w-20"><input v-model="newProduct.price" class="input" placeholder="Precio" /></div>
            <button class="btn-primary" @click="addChild('products', { ...newProduct }, () => { newProduct.name = ''; newProduct.price = ''; newProduct.url = '' })">+</button>
          </div>
          <div v-for="p in products" :key="p.id" class="card flex items-center justify-between p-3">
            <span class="text-sm">{{ p.name }} <span v-if="p.price" class="text-slate-400">· {{ p.price }}</span></span>
            <button v-if="canEdit" class="btn-ghost text-rose-600" @click="deleteChild('products', p.id)"><AppIcon name="close" :size="16" /></button>
          </div>
        </div>
        <div class="space-y-3">
          <h3 class="font-semibold text-slate-900 dark:text-white">Servicios</h3>
          <div v-if="canEdit" class="card flex flex-wrap items-end gap-2 p-4">
            <div class="flex-1"><input v-model="newService.name" class="input" placeholder="Nombre" /></div>
            <button class="btn-primary" @click="addChild('services', { ...newService }, () => { newService.name = ''; newService.url = '' })">+</button>
          </div>
          <div v-for="s in services" :key="s.id" class="card flex items-center justify-between p-3">
            <span class="text-sm">{{ s.name }}</span>
            <button v-if="canEdit" class="btn-ghost text-rose-600" @click="deleteChild('services', s.id)"><AppIcon name="close" :size="16" /></button>
          </div>
        </div>
      </div>

      <!-- Conocimiento -->
      <div v-show="activeTab === 'conocimiento'" class="space-y-4">
        <div v-if="canEdit" class="card space-y-3 p-4">
          <div class="flex flex-wrap gap-2">
            <select v-model="newKnowledge.type" class="input w-auto">
              <option value="faq">FAQ</option>
              <option value="note">Nota</option>
              <option value="url">URL</option>
            </select>
            <input v-model="newKnowledge.title" class="input flex-1" placeholder="Título / pregunta" />
          </div>
          <textarea v-if="newKnowledge.type !== 'url'" v-model="newKnowledge.body" rows="2" class="input" placeholder="Contenido / respuesta" />
          <input v-else v-model="newKnowledge.url" class="input" placeholder="https://" />
          <div class="flex justify-end">
            <button class="btn-primary" @click="addChild('knowledge', { ...newKnowledge }, () => { newKnowledge.title = ''; newKnowledge.body = ''; newKnowledge.url = '' })">Añadir</button>
          </div>
        </div>
        <EmptyState v-if="knowledge.length === 0" icon="sparkles" title="Base de conocimiento vacía" description="Añade FAQs, notas o URLs de referencia." />
        <div v-for="k in knowledge" :key="k.id" class="card p-4">
          <div class="flex items-center justify-between">
            <span class="rounded bg-slate-100 px-2 py-0.5 text-xs uppercase text-slate-500 dark:bg-slate-800">{{ k.type }}</span>
            <button v-if="canEdit" class="btn-ghost text-rose-600" @click="deleteChild('knowledge', k.id)"><AppIcon name="close" :size="16" /></button>
          </div>
          <p class="mt-1 font-medium text-slate-800 dark:text-slate-100">{{ k.title }}</p>
          <p v-if="k.body" class="text-sm text-slate-500">{{ k.body }}</p>
          <a v-if="k.url" :href="k.url" target="_blank" class="text-sm text-brand-600 hover:underline">{{ k.url }}</a>
        </div>
      </div>

      <!-- Medios -->
      <div v-show="activeTab === 'medios'" class="space-y-4">
        <div v-if="auth.can('content.create')" class="card flex items-center gap-3 p-4">
          <label class="btn-primary cursor-pointer">
            <Spinner v-if="uploading" :size="18" />
            <AppIcon v-else name="plus" :size="18" /> Subir archivo
            <input type="file" class="hidden" accept="image/*,video/mp4,application/pdf" :disabled="uploading" @change="uploadMedia" />
          </label>
          <span class="text-sm text-slate-400">Imágenes, vídeo MP4 o PDF (máx. 50 MB)</span>
        </div>
        <EmptyState v-if="media.length === 0" icon="brands" title="Sin archivos" description="Sube imágenes y documentos de tu marca." />
        <div v-else class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
          <div v-for="m in media" :key="m.id" class="card overflow-hidden">
            <div class="flex aspect-video items-center justify-center bg-slate-100 dark:bg-slate-800">
              <img v-if="m.is_image" :src="m.url" :alt="m.original_name" class="h-full w-full object-cover" />
              <AppIcon v-else name="content" :size="32" class="text-slate-400" />
            </div>
            <div class="flex items-center justify-between gap-2 p-2">
              <div class="min-w-0">
                <p class="truncate text-xs font-medium text-slate-700 dark:text-slate-200">{{ m.original_name }}</p>
                <p class="text-[10px] text-slate-400">{{ formatSize(m.size_bytes) }}</p>
              </div>
              <button v-if="auth.can('content.delete')" class="shrink-0 text-rose-500 hover:text-rose-700" @click="deleteMedia(m.id)">
                <AppIcon name="close" :size="16" />
              </button>
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
