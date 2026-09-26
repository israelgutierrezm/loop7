<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import { useConfirmStore } from '@/stores/confirm'
import { apiErrorMessage, apiValidationErrors } from '@/utils/errors'
import { date } from '@/utils/format'
import { useRoute } from 'vue-router'
import { useQueryAction } from '@/composables/useQueryAction'
import PageHeader from '@/components/ui/PageHeader.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import ModalDialog from '@/components/ui/ModalDialog.vue'
import StatusBadge, { type BadgeTone } from '@/components/ui/StatusBadge.vue'
import BrandPicker from '@/components/BrandPicker.vue'
import Spinner from '@/components/ui/Spinner.vue'
import AppIcon from '@/components/AppIcon.vue'

interface Campaign {
  id: string
  name: string
  description: string | null
  objective: string | null
  status: string
  status_label: string
  starts_at: string | null
  ends_at: string | null
  content_count: number
}

const STATUSES = [
  { value: 'draft', label: 'Borrador' },
  { value: 'active', label: 'Activa' },
  { value: 'completed', label: 'Completada' },
  { value: 'archived', label: 'Archivada' },
]
const TONES: Record<string, BadgeTone> = { draft: 'neutral', active: 'success', completed: 'info', archived: 'neutral' }

const auth = useAuthStore()
const toasts = useToastStore()
const confirmDialog = useConfirmStore()

// ?brand=… (enlace del buscador de comandos) si el usuario accede a esa marca.
const route = useRoute()
const queryBrand = typeof route.query.brand === 'string' ? route.query.brand : null
const brandId = ref<string | null>(
  (queryBrand && auth.brands.some((b) => b.id === queryBrand) ? queryBrand : null) ?? auth.brands[0]?.id ?? null,
)
// También si ya estaba en Campañas y el buscador enlaza otra marca.
watch(
  () => route.query.brand,
  (brand) => {
    if (typeof brand === 'string' && auth.brands.some((b) => b.id === brand)) brandId.value = brand
  },
)
const statusFilter = ref('')
const campaigns = ref<Campaign[]>([])
const loading = ref(false)
const failed = ref(false)

const modalOpen = ref(false)
const saving = ref(false)
const editingId = ref<string | null>(null)
const form = reactive({ name: '', objective: '', description: '', status: 'draft', starts_at: '', ends_at: '' })
const errors = ref<Record<string, string[]>>({})

const visible = computed(() => (statusFilter.value ? campaigns.value.filter((c) => c.status === statusFilter.value) : campaigns.value))

function toDateInput(iso: string | null): string {
  return iso ? iso.slice(0, 10) : ''
}

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

function openCreate(): void {
  editingId.value = null
  Object.assign(form, { name: '', objective: '', description: '', status: 'draft', starts_at: '', ends_at: '' })
  errors.value = {}
  modalOpen.value = true
}

function openEdit(c: Campaign): void {
  editingId.value = c.id
  Object.assign(form, {
    name: c.name,
    objective: c.objective ?? '',
    description: c.description ?? '',
    status: c.status,
    starts_at: toDateInput(c.starts_at),
    ends_at: toDateInput(c.ends_at),
  })
  errors.value = {}
  modalOpen.value = true
}

async function save(): Promise<void> {
  if (!brandId.value) return
  saving.value = true
  errors.value = {}
  const payload = {
    name: form.name,
    objective: form.objective || null,
    description: form.description || null,
    status: form.status,
    starts_at: form.starts_at || null,
    ends_at: form.ends_at || null,
  }
  try {
    if (editingId.value) {
      const { data } = await http.patch(`/campaigns/${editingId.value}`, payload)
      const i = campaigns.value.findIndex((c) => c.id === editingId.value)
      if (i >= 0) campaigns.value[i] = data.data
      toasts.success('Campaña actualizada.')
    } else {
      const { data } = await http.post(`/brands/${brandId.value}/campaigns`, payload)
      campaigns.value.unshift(data.data)
      toasts.success('Campaña creada.')
    }
    modalOpen.value = false
  } catch (e) {
    errors.value = apiValidationErrors(e)
    if (!Object.keys(errors.value).length) toasts.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

async function remove(c: Campaign): Promise<void> {
  const ok = await confirmDialog.ask({
    title: 'Eliminar campaña',
    message: `Se eliminará «${c.name}». Sus ${c.content_count} contenido(s) se conservan, sin campaña.`,
    confirmText: 'Eliminar',
    danger: true,
  })
  if (!ok) return
  try {
    await http.delete(`/campaigns/${c.id}`)
    campaigns.value = campaigns.value.filter((x) => x.id !== c.id)
    toasts.success('Campaña eliminada.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

watch(brandId, load)
onMounted(load)
// Buscador de comandos → «Nueva campaña».
useQueryAction('crear', () => {
  if (auth.can('campaigns.create') && brandId.value) openCreate()
})
</script>

<template>
  <div>
    <PageHeader title="Campañas" description="Agrupa contenido por objetivo y fechas.">
      <template #actions>
        <BrandPicker v-model="brandId" />
        <button v-if="auth.can('campaigns.create') && brandId" class="btn-primary" @click="openCreate">
          <AppIcon name="plus" :size="18" /> Nueva
        </button>
      </template>
    </PageHeader>

    <EmptyState v-if="!brandId" icon="brands" title="Crea una marca primero" />
    <template v-else>
      <div v-if="campaigns.length" class="mb-4 inline-flex rounded-lg border border-slate-200 bg-white p-1 text-sm dark:border-slate-800 dark:bg-slate-900" role="tablist" aria-label="Filtrar por estado">
        <button
          v-for="s in [{ value: '', label: 'Todas' }, ...STATUSES]"
          :key="s.value"
          role="tab"
          :aria-selected="statusFilter === s.value"
          class="rounded-md px-3 py-1 font-medium transition"
          :class="statusFilter === s.value ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'"
          @click="statusFilter = s.value"
        >
          {{ s.label }}
        </button>
      </div>

      <div v-if="loading" class="card p-6"><div v-for="n in 3" :key="n" class="skeleton my-2 h-12 w-full" /></div>
      <ErrorState v-else-if="failed" @retry="load" />
      <EmptyState
        v-else-if="visible.length === 0"
        icon="sparkles"
        :title="campaigns.length ? 'Ninguna campaña en este estado' : 'Sin campañas'"
        :description="campaigns.length ? '' : 'Crea tu primera campaña para agrupar contenido.'"
      />

      <div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <article v-for="c in visible" :key="c.id" class="card flex flex-col p-5">
          <div class="flex items-start justify-between gap-2">
            <h2 class="font-semibold text-slate-900 dark:text-white">{{ c.name }}</h2>
            <StatusBadge :tone="TONES[c.status] ?? 'neutral'" dot>{{ c.status_label }}</StatusBadge>
          </div>
          <p class="mt-1 text-sm text-slate-500">{{ c.objective ?? 'Sin objetivo' }}</p>
          <p v-if="c.description" class="mt-2 line-clamp-3 text-sm text-slate-600 dark:text-slate-300">{{ c.description }}</p>
          <p class="mt-3 flex items-center gap-1.5 text-xs text-slate-400">
            <AppIcon name="calendar" :size="14" />
            {{ c.starts_at || c.ends_at ? `${date(c.starts_at)} – ${date(c.ends_at)}` : 'Sin fechas' }}
          </p>
          <div class="mt-auto flex items-center justify-between gap-2 pt-4">
            <RouterLink
              :to="{ path: '/app/content', query: { brand: brandId, campaign: c.id } }"
              class="text-sm font-medium text-brand-600 hover:underline dark:text-brand-400"
            >
              {{ c.content_count }} contenido(s) →
            </RouterLink>
            <div class="flex gap-1">
              <button v-if="auth.can('campaigns.update')" class="btn-ghost px-2 py-1 text-xs" @click="openEdit(c)">Editar</button>
              <button v-if="auth.can('campaigns.delete')" class="btn-ghost px-2 py-1 text-xs text-rose-600" :aria-label="`Eliminar ${c.name}`" @click="remove(c)">
                <AppIcon name="close" :size="14" />
              </button>
            </div>
          </div>
        </article>
      </div>
    </template>

    <ModalDialog :open="modalOpen" :title="editingId ? 'Editar campaña' : 'Nueva campaña'" @close="modalOpen = false">
      <form class="space-y-4" @submit.prevent="save">
        <div>
          <label class="label" for="cp-name">Nombre</label>
          <input id="cp-name" v-model="form.name" type="text" required maxlength="255" class="input" />
          <p v-if="errors.name" class="mt-1 text-xs text-rose-600">{{ errors.name[0] }}</p>
        </div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label class="label" for="cp-obj">Objetivo</label>
            <input id="cp-obj" v-model="form.objective" type="text" maxlength="255" class="input" placeholder="Ventas, alcance, registros…" />
          </div>
          <div>
            <label class="label" for="cp-status">Estado</label>
            <select id="cp-status" v-model="form.status" class="input">
              <option v-for="s in STATUSES" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
          </div>
        </div>
        <div>
          <label class="label" for="cp-desc">Descripción</label>
          <textarea id="cp-desc" v-model="form.description" rows="3" maxlength="2000" class="input" />
        </div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label class="label" for="cp-start">Inicio</label>
            <input id="cp-start" v-model="form.starts_at" type="date" class="input" />
          </div>
          <div>
            <label class="label" for="cp-end">Fin</label>
            <input id="cp-end" v-model="form.ends_at" type="date" :min="form.starts_at || undefined" class="input" />
            <p v-if="errors.ends_at" class="mt-1 text-xs text-rose-600">{{ errors.ends_at[0] }}</p>
          </div>
        </div>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" class="btn-secondary" @click="modalOpen = false">Cancelar</button>
          <button type="submit" class="btn-primary" :disabled="saving">
            <Spinner v-if="saving" :size="18" /> {{ editingId ? 'Guardar' : 'Crear' }}
          </button>
        </div>
      </form>
    </ModalDialog>
  </div>
</template>
