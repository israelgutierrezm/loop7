<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import { useConfirmStore } from '@/stores/confirm'
import { apiErrorMessage, apiValidationErrors } from '@/utils/errors'
import { bytes } from '@/utils/format'
import PageHeader from '@/components/ui/PageHeader.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Spinner from '@/components/ui/Spinner.vue'
import AppIcon from '@/components/AppIcon.vue'
import BrandAvatar from '@/components/BrandAvatar.vue'
import MediaPicker, { type PickedMedia } from '@/components/media/MediaPicker.vue'
import BrandSocialPanel from '@/components/social/BrandSocialPanel.vue'
import type { Brand, SocialProviderOption } from '@/types/models'

interface Audience { id: string; name: string; description: string | null }
interface Product { id: string; name: string; description: string | null; price: string | null; url: string | null }
interface Service { id: string; name: string; description: string | null; url: string | null }
interface Knowledge { id: string; type: string; title: string; body: string | null; url: string | null }
interface MediaItem { id: string; original_name: string; url: string; is_image: boolean; size_bytes: number }

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toasts = useToastStore()
const confirmDialog = useConfirmStore()
const brandId = route.params.brand as string

const loading = ref(true)
const failed = ref(false)
const brand = ref<Brand | null>(null)
const activeTab = ref<'identidad' | 'audiencias' | 'oferta' | 'conocimiento' | 'medios' | 'redes'>('identidad')

const canEdit = computed(() => auth.can('brands.update'))

// --- Datos de la marca ---
const details = reactive({ name: '', website: '', description: '', primary_color: '#6366f1', secondary_color: '#e0e7ff' })
const detailErrors = ref<Record<string, string[]>>({})
const savingDetails = ref(false)
const pickingLogo = ref(false)

// --- Voz y mensajes (Brand Brain) ---
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
const mediaTotal = ref(0)
const socialProviders = ref<SocialProviderOption[]>([])

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

function hydrateDetails(b: Brand): void {
  details.name = b.name
  details.website = b.website ?? ''
  details.description = b.description ?? ''
  details.primary_color = b.primary_color ?? '#6366f1'
  details.secondary_color = b.secondary_color ?? '#e0e7ff'
}

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const [brandResp, brain, mediaResp, providersResp] = await Promise.all([
      http.get(`/brands/${brandId}`),
      http.get(`/brands/${brandId}/brain`),
      http.get(`/brands/${brandId}/media`, { params: { per_page: 12 } }),
      http.get(`/brands/${brandId}/social/providers`),
    ])
    brand.value = brandResp.data.data
    hydrateDetails(brandResp.data.data)
    const d = brain.data.data
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
    mediaTotal.value = mediaResp.data.meta.total ?? media.value.length
    socialProviders.value = providersResp.data.data
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

async function saveDetails(): Promise<void> {
  savingDetails.value = true
  detailErrors.value = {}
  try {
    const { data } = await http.patch(`/brands/${brandId}`, {
      name: details.name,
      website: details.website || null,
      description: details.description || null,
      primary_color: details.primary_color,
      secondary_color: details.secondary_color,
    })
    brand.value = data.data
    toasts.success('Datos de la marca guardados.')
    await auth.loadContext()
  } catch (e) {
    detailErrors.value = apiValidationErrors(e)
    if (!Object.keys(detailErrors.value).length) toasts.error(apiErrorMessage(e))
  } finally {
    savingDetails.value = false
  }
}

async function setLogo(mediaId: string | null): Promise<void> {
  try {
    const { data } = await http.put(`/brands/${brandId}/logo`, { media: mediaId })
    brand.value = data.data
    toasts.success(mediaId ? 'Logo actualizado.' : 'Logo quitado.')
    await auth.loadContext()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

function onLogoPicked(picked: PickedMedia[]): void {
  pickingLogo.value = false
  if (picked[0]) void setLogo(picked[0].id)
}

async function removeBrand(): Promise<void> {
  if (!brand.value) return
  const ok = await confirmDialog.ask({
    title: 'Eliminar marca',
    message: `Se eliminará «${brand.value.name}»: se cancelan sus publicaciones programadas, se desconectan sus cuentas sociales y se pausan sus automatizaciones. Esta acción no se puede deshacer.`,
    confirmText: 'Eliminar marca',
    danger: true,
  })
  if (!ok) return
  try {
    await http.delete(`/brands/${brandId}`)
    toasts.success('Marca eliminada.')
    await auth.loadContext()
    router.push('/app/brands')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
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
    toasts.success('Voz y mensajes guardados.')
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
    media.value = [data.data, ...media.value].slice(0, 12)
    mediaTotal.value++
    toasts.success('Archivo subido.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    uploading.value = false
    input.value = ''
  }
}

async function deleteMedia(item: MediaItem): Promise<void> {
  const isLogo = brand.value?.logo?.id === item.id
  const ok = await confirmDialog.ask({
    title: 'Eliminar archivo',
    message: `Se eliminará «${item.original_name}»${isLogo ? ', que es el logo de la marca' : ''}. Las publicaciones que lo usan dejarán de mostrarlo.`,
    confirmText: 'Eliminar',
    danger: true,
  })
  if (!ok) return
  try {
    await http.delete(`/media/${item.id}`)
    media.value = media.value.filter((m) => m.id !== item.id)
    mediaTotal.value = Math.max(0, mediaTotal.value - 1)
    if (isLogo && brand.value) brand.value = { ...brand.value, logo: null }
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

function handleSocialReturn(): void {
  const status = route.query.social as string | undefined
  if (!status) return
  activeTab.value = 'redes'
  if (status === 'connected') toasts.success('Cuenta conectada.')
  else if (status === 'denied') toasts.error('Autorización cancelada.')
  else if (status === 'invalid') toasts.error('El enlace de conexión expiró. Inténtalo de nuevo.')
  else if (status === 'limit') toasts.error('Alcanzaste el número de cuentas sociales de tu plan.')
  else if (status === 'error') toasts.error('No se pudo completar la conexión.')
  router.replace({ query: {} })
}

const tabs = [
  { key: 'identidad', label: 'Identidad' },
  { key: 'audiencias', label: 'Audiencias' },
  { key: 'oferta', label: 'Productos y servicios' },
  { key: 'conocimiento', label: 'Conocimiento' },
  { key: 'medios', label: 'Medios' },
  { key: 'redes', label: 'Redes' },
] as const

onMounted(async () => {
  await load()
  handleSocialReturn()
})
</script>

<template>
  <div>
    <PageHeader :title="brand?.name ?? 'Marca'" description="Brand Brain: el contexto que alimenta la IA y el contenido.">
      <template #actions>
        <RouterLink to="/app/brands" class="btn-secondary text-sm">
          <AppIcon name="chevron-left" :size="16" /> Marcas
        </RouterLink>
      </template>
    </PageHeader>

    <div v-if="loading" class="card p-6"><div class="skeleton h-64 w-full" /></div>
    <ErrorState v-else-if="failed || !brand" @retry="load" />

    <template v-else>
      <div class="mb-5 flex gap-1 overflow-x-auto border-b border-slate-200 dark:border-slate-800" role="tablist">
        <button
          v-for="tab in tabs"
          :key="tab.key"
          role="tab"
          :aria-selected="activeTab === tab.key"
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
      <div v-show="activeTab === 'identidad'" class="max-w-3xl space-y-6">
        <!-- Datos de la marca -->
        <form class="card p-6" @submit.prevent="saveDetails">
          <h2 class="mb-4 font-semibold text-slate-900 dark:text-white">Datos de la marca</h2>
          <div class="mb-5 flex flex-wrap items-center gap-4">
            <BrandAvatar :name="details.name || brand.name" :logo-url="brand.logo?.url" :color="details.primary_color" :size="64" />
            <div v-if="canEdit" class="flex flex-wrap gap-2">
              <button type="button" class="btn-secondary text-sm" @click="pickingLogo = true">
                <AppIcon name="brands" :size="16" /> {{ brand.logo ? 'Cambiar logo' : 'Elegir logo' }}
              </button>
              <button v-if="brand.logo" type="button" class="btn-ghost text-sm text-rose-600" @click="setLogo(null)">Quitar logo</button>
            </div>
            <p class="basis-full text-xs text-slate-500">El logo es una imagen de la biblioteca de esta marca.</p>
          </div>

          <fieldset :disabled="!canEdit" class="space-y-4">
            <div>
              <label class="label" for="brand-name">Nombre</label>
              <input id="brand-name" v-model="details.name" required maxlength="255" class="input" />
              <p v-if="detailErrors.name" class="mt-1 text-xs text-rose-600">{{ detailErrors.name[0] }}</p>
            </div>
            <div>
              <label class="label" for="brand-web">Sitio web</label>
              <input id="brand-web" v-model="details.website" type="url" class="input" placeholder="https://" />
              <p v-if="detailErrors.website" class="mt-1 text-xs text-rose-600">{{ detailErrors.website[0] }}</p>
            </div>
            <div>
              <label class="label" for="brand-desc">Descripción</label>
              <textarea id="brand-desc" v-model="details.description" rows="3" maxlength="2000" class="input" placeholder="Qué hace la marca, a quién sirve…" />
            </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div>
                <label class="label" for="brand-c1">Color principal</label>
                <div class="flex items-center gap-2">
                  <input id="brand-c1" v-model="details.primary_color" type="color" class="h-10 w-14 cursor-pointer rounded border border-slate-300 bg-white p-1 dark:border-slate-700" />
                  <input v-model="details.primary_color" class="input font-mono" maxlength="7" aria-label="Color principal (hexadecimal)" />
                </div>
              </div>
              <div>
                <label class="label" for="brand-c2">Color secundario</label>
                <div class="flex items-center gap-2">
                  <input id="brand-c2" v-model="details.secondary_color" type="color" class="h-10 w-14 cursor-pointer rounded border border-slate-300 bg-white p-1 dark:border-slate-700" />
                  <input v-model="details.secondary_color" class="input font-mono" maxlength="7" aria-label="Color secundario (hexadecimal)" />
                </div>
              </div>
            </div>
          </fieldset>
          <div v-if="canEdit" class="mt-6 flex justify-end">
            <button type="submit" class="btn-primary" :disabled="savingDetails">
              <Spinner v-if="savingDetails" :size="18" /> Guardar datos
            </button>
          </div>
        </form>

        <!-- Voz y mensajes -->
        <form class="card p-6" @submit.prevent="saveGuidelines">
          <h2 class="mb-1 font-semibold text-slate-900 dark:text-white">Voz y mensajes</h2>
          <p class="mb-4 text-sm text-slate-500">La IA usa estas pautas en cada texto que genera para la marca.</p>
          <fieldset :disabled="!canEdit" class="space-y-4">
            <div>
              <label class="label" for="g-voice">Voz y tono</label>
              <textarea id="g-voice" v-model="guidelines.voice_tone" rows="2" class="input" placeholder="Cercano, profesional, con humor…" />
            </div>
            <div>
              <label class="label" for="g-cta">Llamada a la acción (CTA)</label>
              <input id="g-cta" v-model="guidelines.cta" class="input" placeholder="Empieza gratis hoy" />
            </div>
            <div>
              <label class="label" for="g-value">Propuestas de valor <span class="text-slate-400">(una por línea)</span></label>
              <textarea id="g-value" v-model="guidelines.value_propositions" rows="3" class="input" />
            </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div>
                <label class="label" for="g-tags">Hashtags <span class="text-slate-400">(separados por coma)</span></label>
                <input id="g-tags" v-model="guidelines.hashtags" class="input" placeholder="#marketing, #pymes" />
              </div>
              <div>
                <label class="label" for="g-banned">Términos prohibidos</label>
                <input id="g-banned" v-model="guidelines.prohibited_terms" class="input" placeholder="barato, gratis total" />
              </div>
            </div>
            <div>
              <label class="label" for="g-vocab">Vocabulario preferido</label>
              <input id="g-vocab" v-model="guidelines.preferred_vocabulary" class="input" />
            </div>
            <div>
              <label class="label" for="g-notes">Notas para la IA</label>
              <textarea id="g-notes" v-model="guidelines.notes" rows="3" class="input" placeholder="Temas a evitar, estacionalidad, estilo de emojis…" />
            </div>
          </fieldset>
          <div v-if="canEdit" class="mt-6 flex justify-end">
            <button type="submit" class="btn-primary" :disabled="savingGuidelines">
              <Spinner v-if="savingGuidelines" :size="18" /> Guardar voz y mensajes
            </button>
          </div>
        </form>

        <!-- Zona de peligro -->
        <section v-if="auth.can('brands.delete')" class="card border-rose-200 p-6 dark:border-rose-900/60" aria-labelledby="danger-title">
          <h2 id="danger-title" class="font-semibold text-rose-700 dark:text-rose-400">Eliminar marca</h2>
          <p class="mt-1 text-sm text-slate-500">
            Se cancelan sus publicaciones programadas, se desconectan sus cuentas sociales y se pausan sus
            automatizaciones. Su contenido y su biblioteca dejan de estar disponibles.
          </p>
          <button type="button" class="btn-secondary mt-4 text-sm text-rose-600" @click="removeBrand">Eliminar marca</button>
        </section>
      </div>

      <!-- Audiencias -->
      <div v-show="activeTab === 'audiencias'" class="space-y-4">
        <form
          v-if="canEdit"
          class="card flex flex-wrap items-end gap-3 p-4"
          @submit.prevent="addChild('audiences', { ...newAudience }, () => { newAudience.name = ''; newAudience.description = '' })"
        >
          <div class="flex-1"><label class="label" for="aud-name">Nombre</label><input id="aud-name" v-model="newAudience.name" required class="input" placeholder="PyMEs tecnológicas" /></div>
          <div class="flex-1"><label class="label" for="aud-desc">Descripción</label><input id="aud-desc" v-model="newAudience.description" class="input" /></div>
          <button type="submit" class="btn-primary">Añadir</button>
        </form>
        <EmptyState v-if="audiences.length === 0" icon="team" title="Sin audiencias" description="Define a quién te diriges." />
        <div v-for="a in audiences" :key="a.id" class="card flex items-center justify-between p-4">
          <div><p class="font-medium text-slate-800 dark:text-slate-100">{{ a.name }}</p><p class="text-sm text-slate-500">{{ a.description }}</p></div>
          <button v-if="canEdit" class="btn-ghost text-rose-600" :aria-label="`Quitar ${a.name}`" @click="deleteChild('audiences', a.id)"><AppIcon name="close" :size="18" /></button>
        </div>
      </div>

      <!-- Productos y servicios -->
      <div v-show="activeTab === 'oferta'" class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div class="space-y-3">
          <h3 class="font-semibold text-slate-900 dark:text-white">Productos</h3>
          <form
            v-if="canEdit"
            class="card flex flex-wrap items-end gap-2 p-4"
            @submit.prevent="addChild('products', { ...newProduct }, () => { newProduct.name = ''; newProduct.price = ''; newProduct.url = '' })"
          >
            <div class="flex-1"><label class="sr-only" for="prod-name">Nombre del producto</label><input id="prod-name" v-model="newProduct.name" required class="input" placeholder="Nombre" /></div>
            <div class="w-24"><label class="sr-only" for="prod-price">Precio</label><input id="prod-price" v-model="newProduct.price" class="input" placeholder="Precio" /></div>
            <button type="submit" class="btn-primary" aria-label="Añadir producto"><AppIcon name="plus" :size="16" /></button>
          </form>
          <div v-for="p in products" :key="p.id" class="card flex items-center justify-between p-3">
            <span class="text-sm">{{ p.name }} <span v-if="p.price" class="text-slate-400">· {{ p.price }}</span></span>
            <button v-if="canEdit" class="btn-ghost text-rose-600" :aria-label="`Quitar ${p.name}`" @click="deleteChild('products', p.id)"><AppIcon name="close" :size="16" /></button>
          </div>
        </div>
        <div class="space-y-3">
          <h3 class="font-semibold text-slate-900 dark:text-white">Servicios</h3>
          <form
            v-if="canEdit"
            class="card flex flex-wrap items-end gap-2 p-4"
            @submit.prevent="addChild('services', { ...newService }, () => { newService.name = ''; newService.url = '' })"
          >
            <div class="flex-1"><label class="sr-only" for="serv-name">Nombre del servicio</label><input id="serv-name" v-model="newService.name" required class="input" placeholder="Nombre" /></div>
            <button type="submit" class="btn-primary" aria-label="Añadir servicio"><AppIcon name="plus" :size="16" /></button>
          </form>
          <div v-for="s in services" :key="s.id" class="card flex items-center justify-between p-3">
            <span class="text-sm">{{ s.name }}</span>
            <button v-if="canEdit" class="btn-ghost text-rose-600" :aria-label="`Quitar ${s.name}`" @click="deleteChild('services', s.id)"><AppIcon name="close" :size="16" /></button>
          </div>
        </div>
      </div>

      <!-- Conocimiento -->
      <div v-show="activeTab === 'conocimiento'" class="space-y-4">
        <form
          v-if="canEdit"
          class="card space-y-3 p-4"
          @submit.prevent="addChild('knowledge', { ...newKnowledge }, () => { newKnowledge.title = ''; newKnowledge.body = ''; newKnowledge.url = '' })"
        >
          <div class="flex flex-wrap gap-2">
            <label class="sr-only" for="kn-type">Tipo</label>
            <select id="kn-type" v-model="newKnowledge.type" class="input w-auto">
              <option value="faq">FAQ</option>
              <option value="note">Nota</option>
              <option value="url">URL</option>
            </select>
            <label class="sr-only" for="kn-title">Título o pregunta</label>
            <input id="kn-title" v-model="newKnowledge.title" required class="input flex-1" placeholder="Título / pregunta" />
          </div>
          <template v-if="newKnowledge.type !== 'url'">
            <label class="sr-only" for="kn-body">Contenido o respuesta</label>
            <textarea id="kn-body" v-model="newKnowledge.body" rows="2" class="input" placeholder="Contenido / respuesta" />
          </template>
          <template v-else>
            <label class="sr-only" for="kn-url">URL</label>
            <input id="kn-url" v-model="newKnowledge.url" type="url" class="input" placeholder="https://" />
          </template>
          <div class="flex justify-end">
            <button type="submit" class="btn-primary">Añadir</button>
          </div>
        </form>
        <EmptyState v-if="knowledge.length === 0" icon="sparkles" title="Base de conocimiento vacía" description="Añade FAQs, notas o URLs de referencia." />
        <div v-for="k in knowledge" :key="k.id" class="card p-4">
          <div class="flex items-center justify-between">
            <span class="rounded bg-slate-100 px-2 py-0.5 text-xs uppercase text-slate-500 dark:bg-slate-800">{{ k.type }}</span>
            <button v-if="canEdit" class="btn-ghost text-rose-600" :aria-label="`Quitar ${k.title}`" @click="deleteChild('knowledge', k.id)"><AppIcon name="close" :size="16" /></button>
          </div>
          <p class="mt-1 font-medium text-slate-800 dark:text-slate-100">{{ k.title }}</p>
          <p v-if="k.body" class="text-sm text-slate-500">{{ k.body }}</p>
          <a v-if="k.url" :href="k.url" target="_blank" rel="noopener noreferrer" class="text-sm text-brand-600 hover:underline">{{ k.url }}</a>
        </div>
      </div>

      <!-- Medios -->
      <div v-show="activeTab === 'medios'" class="space-y-4">
        <div class="card flex flex-wrap items-center gap-3 p-4">
          <label v-if="auth.can('content.create')" class="btn-primary cursor-pointer">
            <Spinner v-if="uploading" :size="18" />
            <AppIcon v-else name="plus" :size="18" /> Subir archivo
            <input type="file" class="hidden" accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,application/pdf" :disabled="uploading" @change="uploadMedia" />
          </label>
          <span class="text-sm text-slate-500">
            {{ mediaTotal }} archivo(s){{ mediaTotal > media.length ? ` · se muestran los ${media.length} más recientes` : '' }}
          </span>
          <RouterLink :to="{ path: '/app/media', query: { brand: brandId } }" class="btn-secondary ml-auto text-sm">
            Abrir en la biblioteca (carpetas y etiquetas)
          </RouterLink>
        </div>
        <EmptyState v-if="media.length === 0" icon="brands" title="Sin archivos" description="Sube imágenes y documentos de tu marca." />
        <div v-else class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
          <div v-for="m in media" :key="m.id" class="card overflow-hidden">
            <div class="relative flex aspect-video items-center justify-center bg-slate-100 dark:bg-slate-800">
              <img v-if="m.is_image" :src="m.url" :alt="m.original_name" loading="lazy" class="h-full w-full object-cover" />
              <AppIcon v-else name="content" :size="32" class="text-slate-400" />
              <span v-if="brand.logo?.id === m.id" class="absolute left-2 top-2 rounded bg-slate-900/70 px-1.5 py-0.5 text-[10px] font-semibold text-white">Logo</span>
            </div>
            <div class="flex items-center justify-between gap-2 p-2">
              <div class="min-w-0">
                <p class="truncate text-xs font-medium text-slate-700 dark:text-slate-200">{{ m.original_name }}</p>
                <p class="text-[10px] text-slate-400">{{ bytes(m.size_bytes) }}</p>
              </div>
              <button
                v-if="auth.can('content.delete')"
                class="shrink-0 text-rose-500 hover:text-rose-700"
                :aria-label="`Eliminar ${m.original_name}`"
                @click="deleteMedia(m)"
              >
                <AppIcon name="close" :size="16" />
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Redes -->
      <div v-if="activeTab === 'redes'" class="card max-w-4xl p-6">
        <h3 class="mb-1 font-semibold text-slate-900 dark:text-white">Cuentas conectadas</h3>
        <p class="mb-4 text-sm text-slate-500">Las publicaciones de esta marca salen por estas cuentas.</p>
        <BrandSocialPanel :brand-id="brandId" :providers="socialProviders" />
      </div>

      <MediaPicker
        :open="pickingLogo"
        :brand-id="brandId"
        :selected="brand.logo ? [brand.logo.id] : []"
        :max="1"
        :accept-video="false"
        @close="pickingLogo = false"
        @confirm="onLogoPicked"
      />
    </template>
  </div>
</template>
