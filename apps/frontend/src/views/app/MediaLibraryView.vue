<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useDebounceFn } from '@vueuse/core'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import { useConfirmStore } from '@/stores/confirm'
import { apiErrorMessage, apiValidationErrors } from '@/utils/errors'
import { bytes } from '@/utils/format'
import PageHeader from '@/components/ui/PageHeader.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import ModalDialog from '@/components/ui/ModalDialog.vue'
import BrandPicker from '@/components/BrandPicker.vue'
import Spinner from '@/components/ui/Spinner.vue'
import AppIcon from '@/components/AppIcon.vue'

interface Ref { id: string; name: string }
interface Asset {
  id: string
  original_name: string
  mime_type: string
  size_bytes: number
  width: number | null
  height: number | null
  is_image: boolean
  is_video: boolean
  folder: Ref | null
  tags: Ref[]
  url: string
}
interface Folder extends Ref { count: number }
interface Tag extends Ref { count: number }

const auth = useAuthStore()
const toasts = useToastStore()
const confirmDialog = useConfirmStore()
const route = useRoute()

const queryBrand = typeof route.query.brand === 'string' ? route.query.brand : null
const brandId = ref<string | null>(
  (queryBrand && auth.brands.some((b) => b.id === queryBrand) ? queryBrand : null) ?? auth.brands[0]?.id ?? null,
)
const canUpload = auth.can('content.create')
const canOrganize = auth.can('content.update')
const canDelete = auth.can('content.delete')

// Filtros
const folder = ref('') // '' = todos · 'none' = sin carpeta · id
const type = ref('')
const tag = ref('')
const search = ref('')
const typeOptions = [
  { value: '', label: 'Todo' },
  { value: 'image', label: 'Imágenes' },
  { value: 'video', label: 'Vídeos' },
  { value: 'document', label: 'Documentos' },
]

// Datos
const assets = ref<Asset[]>([])
const folders = ref<Folder[]>([])
const totals = reactive({ total: 0, unfiled: 0 })
const tags = ref<Tag[]>([])
const page = ref(1)
const lastPage = ref(1)
const loading = ref(false)
const loadingMore = ref(false)
const failed = ref(false)
const uploading = ref(false)

// Carpetas
const newFolder = ref('')
const editingFolder = ref<string | null>(null)
const folderName = ref('')

// Organizar un archivo
const organizing = ref<Asset | null>(null)
const organizeForm = reactive({ folder: '', tags: '' })
const organizeErrors = ref<Record<string, string[]>>({})
const savingOrganize = ref(false)

const currentFolderName = computed(() => folders.value.find((f) => f.id === folder.value)?.name ?? null)

async function loadFolders(): Promise<void> {
  if (!brandId.value) return
  const [foldersResp, tagsResp] = await Promise.all([
    http.get(`/brands/${brandId.value}/media/folders`),
    http.get(`/brands/${brandId.value}/media/tags`),
  ])
  folders.value = foldersResp.data.data
  totals.total = foldersResp.data.meta.total ?? 0
  totals.unfiled = foldersResp.data.meta.unfiled ?? 0
  tags.value = tagsResp.data.data
  if (tag.value && !tags.value.some((t) => t.id === tag.value)) tag.value = ''
}

async function fetchPage(p: number): Promise<void> {
  if (!brandId.value) return
  const { data } = await http.get(`/brands/${brandId.value}/media`, {
    params: {
      page: p,
      per_page: 30,
      folder: folder.value || undefined,
      type: type.value || undefined,
      tag: tag.value || undefined,
      q: search.value.trim() || undefined,
    },
  })
  assets.value = p === 1 ? data.data : [...assets.value, ...data.data]
  page.value = data.meta.current_page ?? p
  lastPage.value = data.meta.last_page ?? p
}

async function load(): Promise<void> {
  if (!brandId.value) return
  loading.value = true
  failed.value = false
  try {
    await Promise.all([fetchPage(1), loadFolders()])
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

async function reloadAssets(): Promise<void> {
  loading.value = true
  try {
    await fetchPage(1)
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

async function upload(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  const files = Array.from(input.files ?? [])
  if (files.length === 0 || !brandId.value) return
  uploading.value = true
  let uploaded = 0
  for (const file of files) {
    const form = new FormData()
    form.append('file', file)
    if (folder.value && folder.value !== 'none') form.append('folder', folder.value)
    try {
      await http.post(`/brands/${brandId.value}/media`, form)
      uploaded++
    } catch (e) {
      toasts.error(`${file.name}: ${apiErrorMessage(e)}`)
    }
  }
  uploading.value = false
  input.value = ''
  if (uploaded > 0) {
    toasts.success(uploaded === 1 ? 'Archivo subido.' : `${uploaded} archivos subidos.`)
    await Promise.all([reloadAssets(), loadFolders()])
  }
}

async function remove(asset: Asset): Promise<void> {
  const ok = await confirmDialog.ask({
    title: 'Eliminar archivo',
    message: `Se eliminará «${asset.original_name}». Las publicaciones que lo usan dejarán de mostrarlo.`,
    confirmText: 'Eliminar',
    danger: true,
  })
  if (!ok) return
  try {
    await http.delete(`/media/${asset.id}`)
    assets.value = assets.value.filter((a) => a.id !== asset.id)
    toasts.success('Archivo eliminado.')
    await loadFolders()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

// --- Carpetas ---
async function createFolder(): Promise<void> {
  const name = newFolder.value.trim()
  if (!name || !brandId.value) return
  try {
    const { data } = await http.post(`/brands/${brandId.value}/media/folders`, { name })
    newFolder.value = ''
    await loadFolders()
    folder.value = data.data.id
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

function startRename(f: Folder): void {
  editingFolder.value = f.id
  folderName.value = f.name
}

async function saveRename(f: Folder): Promise<void> {
  const name = folderName.value.trim()
  editingFolder.value = null
  if (!name || name === f.name) return
  try {
    await http.patch(`/media/folders/${f.id}`, { name })
    await loadFolders()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function removeFolder(f: Folder): Promise<void> {
  const ok = await confirmDialog.ask({
    title: 'Eliminar carpeta',
    message: `Se eliminará «${f.name}». Sus ${f.count} archivo(s) no se borran: quedan sin carpeta.`,
    confirmText: 'Eliminar carpeta',
    danger: true,
  })
  if (!ok) return
  try {
    await http.delete(`/media/folders/${f.id}`)
    if (folder.value === f.id) folder.value = ''
    await Promise.all([loadFolders(), reloadAssets()])
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

// --- Organizar un archivo ---
function openOrganize(asset: Asset): void {
  organizing.value = asset
  organizeForm.folder = asset.folder?.id ?? ''
  organizeForm.tags = asset.tags.map((t) => t.name).join(', ')
  organizeErrors.value = {}
}

async function saveOrganize(): Promise<void> {
  if (!organizing.value) return
  savingOrganize.value = true
  organizeErrors.value = {}
  try {
    const { data } = await http.patch(`/media/${organizing.value.id}`, {
      folder: organizeForm.folder || null,
      tags: organizeForm.tags.split(',').map((t) => t.trim()).filter(Boolean),
    })
    const index = assets.value.findIndex((a) => a.id === data.data.id)
    if (index >= 0) assets.value[index] = data.data
    organizing.value = null
    toasts.success('Archivo organizado.')
    await loadFolders()
    // Si ya no cumple el filtro actual, sale de la vista.
    if (folder.value && (folder.value === 'none' ? data.data.folder : data.data.folder?.id !== folder.value)) {
      assets.value = assets.value.filter((a) => a.id !== data.data.id)
    }
  } catch (e) {
    organizeErrors.value = apiValidationErrors(e)
    if (!Object.keys(organizeErrors.value).length) toasts.error(apiErrorMessage(e))
  } finally {
    savingOrganize.value = false
  }
}

function dimensions(a: Asset): string {
  return a.width && a.height ? ` · ${a.width}×${a.height}` : ''
}

const debouncedReload = useDebounceFn(reloadAssets, 350)

watch(brandId, () => {
  folder.value = ''
  tag.value = ''
  search.value = ''
  load()
})
watch([folder, type, tag], reloadAssets)
watch(search, () => debouncedReload())
onMounted(load)
</script>

<template>
  <div>
    <PageHeader title="Biblioteca" description="Imágenes, vídeos y documentos de tus marcas, organizados por carpetas y etiquetas.">
      <template #actions>
        <BrandPicker v-model="brandId" />
        <label v-if="brandId && canUpload" class="btn-primary cursor-pointer text-sm">
          <Spinner v-if="uploading" :size="16" />
          <AppIcon v-else name="plus" :size="16" /> Subir
          <input
            type="file"
            class="sr-only"
            multiple
            accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,application/pdf"
            :disabled="uploading"
            @change="upload"
          />
        </label>
      </template>
    </PageHeader>

    <EmptyState v-if="!brandId" icon="brands" title="Crea una marca primero" description="Los archivos se organizan por marca." />

    <div v-else class="grid grid-cols-1 gap-6 lg:grid-cols-[15rem_1fr]">
      <!-- Carpetas -->
      <aside class="card h-fit p-3" aria-label="Carpetas">
        <p class="px-2 pb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Carpetas</p>
        <ul class="space-y-0.5 text-sm">
          <li>
            <button
              class="flex w-full items-center justify-between rounded-md px-2 py-1.5 text-left"
              :class="folder === '' ? 'bg-brand-50 font-medium text-brand-700 dark:bg-brand-950/50 dark:text-brand-300' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'"
              :aria-current="folder === '' ? 'true' : undefined"
              @click="folder = ''"
            >
              Todos los archivos <span class="text-xs text-slate-400">{{ totals.total }}</span>
            </button>
          </li>
          <li>
            <button
              class="flex w-full items-center justify-between rounded-md px-2 py-1.5 text-left"
              :class="folder === 'none' ? 'bg-brand-50 font-medium text-brand-700 dark:bg-brand-950/50 dark:text-brand-300' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'"
              :aria-current="folder === 'none' ? 'true' : undefined"
              @click="folder = 'none'"
            >
              Sin carpeta <span class="text-xs text-slate-400">{{ totals.unfiled }}</span>
            </button>
          </li>
          <li v-for="f in folders" :key="f.id" class="group">
            <form v-if="editingFolder === f.id" class="px-1 py-0.5" @submit.prevent="saveRename(f)">
              <label class="sr-only" :for="`rename-${f.id}`">Nuevo nombre</label>
              <input
                :id="`rename-${f.id}`"
                v-model="folderName"
                maxlength="60"
                class="input py-1 text-sm"
                autofocus
                @blur="saveRename(f)"
                @keydown.esc="editingFolder = null"
              />
            </form>
            <div v-else class="flex items-center">
              <button
                class="flex min-w-0 flex-1 items-center justify-between gap-2 rounded-md px-2 py-1.5 text-left"
                :class="folder === f.id ? 'bg-brand-50 font-medium text-brand-700 dark:bg-brand-950/50 dark:text-brand-300' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'"
                :aria-current="folder === f.id ? 'true' : undefined"
                @click="folder = f.id"
              >
                <span class="truncate">{{ f.name }}</span>
                <span class="text-xs text-slate-400">{{ f.count }}</span>
              </button>
              <template v-if="canOrganize">
                <button class="p-1 text-slate-400 opacity-0 hover:text-slate-600 focus:opacity-100 group-hover:opacity-100" :aria-label="`Renombrar ${f.name}`" @click="startRename(f)">
                  <AppIcon name="content" :size="14" />
                </button>
                <button class="p-1 text-slate-400 opacity-0 hover:text-rose-600 focus:opacity-100 group-hover:opacity-100" :aria-label="`Eliminar carpeta ${f.name}`" @click="removeFolder(f)">
                  <AppIcon name="close" :size="14" />
                </button>
              </template>
            </div>
          </li>
        </ul>
        <form v-if="canOrganize" class="mt-3 flex gap-1 border-t border-slate-100 pt-3 dark:border-slate-800" @submit.prevent="createFolder">
          <label class="sr-only" for="new-folder">Nueva carpeta</label>
          <input id="new-folder" v-model="newFolder" maxlength="60" class="input py-1.5 text-sm" placeholder="Nueva carpeta" />
          <button type="submit" class="btn-secondary px-2.5" aria-label="Crear carpeta" :disabled="!newFolder.trim()">
            <AppIcon name="plus" :size="16" />
          </button>
        </form>
      </aside>

      <!-- Archivos -->
      <section class="min-w-0">
        <div class="mb-4 flex flex-wrap items-center gap-2">
          <div class="relative min-w-[12rem] flex-1">
            <AppIcon name="search" :size="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <label class="sr-only" for="media-search">Buscar por nombre</label>
            <input id="media-search" v-model="search" type="search" class="input pl-9" placeholder="Buscar por nombre…" />
          </div>
          <div class="inline-flex rounded-lg border border-slate-200 bg-white p-1 text-sm dark:border-slate-800 dark:bg-slate-900" role="tablist" aria-label="Tipo de archivo">
            <button
              v-for="t in typeOptions"
              :key="t.value"
              role="tab"
              :aria-selected="type === t.value"
              class="rounded-md px-2.5 py-1 font-medium transition"
              :class="type === t.value ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'"
              @click="type = t.value"
            >
              {{ t.label }}
            </button>
          </div>
          <label class="sr-only" for="media-tag">Etiqueta</label>
          <select v-if="tags.length" id="media-tag" v-model="tag" class="input w-auto">
            <option value="">Todas las etiquetas</option>
            <option v-for="t in tags" :key="t.id" :value="t.id">{{ t.name }} ({{ t.count }})</option>
          </select>
        </div>

        <p v-if="currentFolderName && canUpload" class="mb-3 text-xs text-slate-500">
          Lo que subas ahora se guardará en «{{ currentFolderName }}».
        </p>

        <div v-if="loading" class="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-4">
          <div v-for="n in 8" :key="n" class="skeleton aspect-[4/3] rounded-xl" />
        </div>
        <ErrorState v-else-if="failed" @retry="load" />
        <EmptyState
          v-else-if="assets.length === 0"
          icon="brands"
          :title="search || type || tag || folder ? 'Nada coincide con estos filtros' : 'Sin archivos'"
          :description="search || type || tag || folder ? 'Prueba con otra carpeta, tipo o etiqueta.' : 'Sube imágenes, vídeos o documentos de tu marca.'"
        />

        <ul v-else class="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-4">
          <li v-for="m in assets" :key="m.id" class="card flex flex-col overflow-hidden">
            <a :href="m.url" target="_blank" rel="noopener" class="relative flex aspect-video items-center justify-center bg-slate-100 dark:bg-slate-800" :aria-label="`Abrir ${m.original_name}`">
              <img v-if="m.is_image" :src="m.url" :alt="m.original_name" loading="lazy" class="h-full w-full object-cover" />
              <video v-else-if="m.is_video" :src="m.url" class="h-full w-full object-cover" muted preload="metadata" />
              <AppIcon v-else name="content" :size="32" class="text-slate-400" />
              <span v-if="m.is_video" class="absolute left-2 top-2 rounded bg-slate-900/70 px-1.5 py-0.5 text-[10px] font-semibold text-white">VÍDEO</span>
            </a>
            <div class="flex flex-1 flex-col gap-1.5 p-2.5">
              <p class="truncate text-xs font-medium text-slate-700 dark:text-slate-200" :title="m.original_name">{{ m.original_name }}</p>
              <p class="text-[11px] text-slate-400">{{ bytes(m.size_bytes) }}{{ dimensions(m) }}</p>
              <div class="flex flex-wrap gap-1">
                <span v-if="m.folder && folder === ''" class="inline-flex items-center gap-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-500 dark:bg-slate-800">
                  {{ m.folder.name }}
                </span>
                <span v-for="t in m.tags" :key="t.id" class="rounded bg-brand-50 px-1.5 py-0.5 text-[10px] text-brand-700 dark:bg-brand-950/50 dark:text-brand-300">
                  {{ t.name }}
                </span>
              </div>
              <div v-if="canOrganize || canDelete" class="mt-auto flex justify-end gap-1 pt-1">
                <button v-if="canOrganize" class="btn-ghost px-2 py-1 text-xs" @click="openOrganize(m)">Organizar</button>
                <button v-if="canDelete" class="btn-ghost px-2 py-1 text-xs text-rose-600" :aria-label="`Eliminar ${m.original_name}`" @click="remove(m)">
                  <AppIcon name="close" :size="14" />
                </button>
              </div>
            </div>
          </li>
        </ul>

        <div v-if="!loading && page < lastPage" class="mt-5 text-center">
          <button class="btn-secondary text-sm" :disabled="loadingMore" @click="more">
            <Spinner v-if="loadingMore" :size="16" /> Cargar más
          </button>
        </div>
      </section>
    </div>

    <!-- Organizar -->
    <ModalDialog :open="organizing !== null" title="Organizar archivo" :description="organizing?.original_name" @close="organizing = null">
      <form class="space-y-4" @submit.prevent="saveOrganize">
        <div>
          <label class="label" for="org-folder">Carpeta</label>
          <select id="org-folder" v-model="organizeForm.folder" class="input">
            <option value="">Sin carpeta</option>
            <option v-for="f in folders" :key="f.id" :value="f.id">{{ f.name }}</option>
          </select>
          <p v-if="organizeErrors.folder" class="mt-1 text-xs text-rose-600">{{ organizeErrors.folder[0] }}</p>
        </div>
        <div>
          <label class="label" for="org-tags">Etiquetas <span class="text-slate-400">(separadas por coma)</span></label>
          <input id="org-tags" v-model="organizeForm.tags" class="input" placeholder="verano, producto, reel" list="media-tag-options" />
          <datalist id="media-tag-options">
            <option v-for="t in tags" :key="t.id" :value="t.name" />
          </datalist>
          <p v-if="organizeErrors.tags" class="mt-1 text-xs text-rose-600">{{ organizeErrors.tags[0] }}</p>
        </div>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" class="btn-secondary" @click="organizing = null">Cancelar</button>
          <button type="submit" class="btn-primary" :disabled="savingOrganize">
            <Spinner v-if="savingOrganize" :size="16" /> Guardar
          </button>
        </div>
      </form>
    </ModalDialog>
  </div>
</template>
