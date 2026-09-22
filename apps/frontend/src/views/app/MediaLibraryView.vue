<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage } from '@/utils/errors'
import PageHeader from '@/components/ui/PageHeader.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import BrandPicker from '@/components/BrandPicker.vue'
import Spinner from '@/components/ui/Spinner.vue'
import AppIcon from '@/components/AppIcon.vue'

interface Asset {
  id: string
  is_image: boolean
  url: string
  original_name: string
  size_bytes: number
}

const auth = useAuthStore()
const toasts = useToastStore()

const brandId = ref<string | null>(auth.brands[0]?.id ?? null)
const assets = ref<Asset[]>([])
const loading = ref(false)
const failed = ref(false)
const uploading = ref(false)

async function load(): Promise<void> {
  if (!brandId.value) return
  loading.value = true
  failed.value = false
  try {
    const { data } = await http.get(`/brands/${brandId.value}/media`)
    assets.value = data.data
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

async function upload(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file || !brandId.value) return
  uploading.value = true
  const form = new FormData()
  form.append('file', file)
  try {
    const { data } = await http.post(`/brands/${brandId.value}/media`, form)
    assets.value.unshift(data.data)
    toasts.success('Archivo subido.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    uploading.value = false
    input.value = ''
  }
}

async function remove(id: string): Promise<void> {
  if (!confirm('¿Eliminar este archivo?')) return
  try {
    await http.delete(`/media/${id}`)
    assets.value = assets.value.filter((a) => a.id !== id)
    toasts.success('Archivo eliminado.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

watch(brandId, load)
onMounted(load)
</script>

<template>
  <div>
    <PageHeader title="Biblioteca" description="Imágenes y documentos de tus marcas.">
      <template #actions>
        <BrandPicker v-model="brandId" />
      </template>
    </PageHeader>

    <EmptyState v-if="!brandId" icon="brands" title="Crea una marca primero" description="Los archivos se organizan por marca." />

    <template v-else>
      <div v-if="auth.can('content.create')" class="card mb-4 flex items-center gap-3 p-4">
        <label class="btn-primary cursor-pointer">
          <Spinner v-if="uploading" :size="18" />
          <AppIcon v-else name="plus" :size="18" /> Subir archivo
          <input
            type="file"
            class="hidden"
            accept="image/*,video/mp4,application/pdf"
            :disabled="uploading"
            @change="upload"
          />
        </label>
        <span class="text-sm text-slate-400">Imágenes, vídeo MP4 o PDF (máx. 50 MB)</span>
      </div>

      <div v-if="loading" class="card p-6"><div class="skeleton h-40 w-full" /></div>
      <ErrorState v-else-if="failed" @retry="load" />
      <EmptyState
        v-else-if="assets.length === 0"
        icon="brands"
        title="Sin archivos"
        description="Sube imágenes y documentos de tu marca."
      />

      <div v-else class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
        <div v-for="m in assets" :key="m.id" class="card overflow-hidden">
          <div class="flex aspect-video items-center justify-center bg-slate-100 dark:bg-slate-800">
            <img v-if="m.is_image" :src="m.url" :alt="m.original_name" class="h-full w-full object-cover" />
            <AppIcon v-else name="content" :size="32" class="text-slate-400" />
          </div>
          <div class="flex items-center justify-between gap-2 p-2">
            <div class="min-w-0">
              <p class="truncate text-xs font-medium text-slate-700 dark:text-slate-200">{{ m.original_name }}</p>
              <p class="text-[10px] text-slate-400">{{ formatSize(m.size_bytes) }}</p>
            </div>
            <button
              v-if="auth.can('content.delete')"
              class="shrink-0 text-rose-500 hover:text-rose-700"
              @click="remove(m.id)"
            >
              <AppIcon name="close" :size="16" />
            </button>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
