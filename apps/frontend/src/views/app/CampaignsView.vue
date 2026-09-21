<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage } from '@/utils/errors'
import PageHeader from '@/components/ui/PageHeader.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import ModalDialog from '@/components/ui/ModalDialog.vue'
import BrandPicker from '@/components/BrandPicker.vue'
import Spinner from '@/components/ui/Spinner.vue'
import AppIcon from '@/components/AppIcon.vue'

interface Campaign { id: string; name: string; objective: string | null; status: string }

const auth = useAuthStore()
const toasts = useToastStore()
const brandId = ref<string | null>(auth.brands[0]?.id ?? null)
const campaigns = ref<Campaign[]>([])
const loading = ref(false)
const failed = ref(false)
const showCreate = ref(false)
const creating = ref(false)
const form = reactive({ name: '', objective: '' })

async function load(): Promise<void> {
  if (!brandId.value) return
  loading.value = true
  failed.value = false
  try {
    const { data } = await http.get(`/brands/${brandId.value}/campaigns`)
    campaigns.value = data.data
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

async function create(): Promise<void> {
  if (!brandId.value) return
  creating.value = true
  try {
    const { data } = await http.post(`/brands/${brandId.value}/campaigns`, { ...form })
    campaigns.value.unshift(data.data)
    showCreate.value = false
    form.name = ''
    form.objective = ''
    toasts.success('Campaña creada.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    creating.value = false
  }
}

watch(brandId, load)
onMounted(load)
</script>

<template>
  <div>
    <PageHeader title="Campañas" description="Agrupa contenido por objetivo.">
      <template #actions>
        <BrandPicker v-model="brandId" />
        <button v-if="auth.can('campaigns.create') && brandId" class="btn-primary" @click="showCreate = true">
          <AppIcon name="plus" :size="18" /> Nueva
        </button>
      </template>
    </PageHeader>

    <EmptyState v-if="!brandId" icon="brands" title="Crea una marca primero" />
    <div v-else-if="loading" class="card p-6"><div v-for="n in 3" :key="n" class="skeleton my-2 h-12 w-full" /></div>
    <ErrorState v-else-if="failed" @retry="load" />
    <EmptyState v-else-if="campaigns.length === 0" icon="sparkles" title="Sin campañas" description="Crea tu primera campaña." />

    <div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <div v-for="c in campaigns" :key="c.id" class="card p-5">
        <p class="font-semibold text-slate-900 dark:text-white">{{ c.name }}</p>
        <p class="mt-1 text-sm text-slate-500">{{ c.objective ?? 'Sin objetivo' }}</p>
        <span class="mt-3 inline-flex rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-600 dark:bg-slate-800">
          {{ c.status }}
        </span>
      </div>
    </div>

    <ModalDialog :open="showCreate" title="Nueva campaña" @close="showCreate = false">
      <form class="space-y-4" @submit.prevent="create">
        <div>
          <label class="label" for="cp-name">Nombre</label>
          <input id="cp-name" v-model="form.name" type="text" required class="input" />
        </div>
        <div>
          <label class="label" for="cp-obj">Objetivo</label>
          <input id="cp-obj" v-model="form.objective" type="text" class="input" />
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
