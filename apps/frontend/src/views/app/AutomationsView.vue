<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import { useConfirmStore } from '@/stores/confirm'
import { apiErrorMessage } from '@/utils/errors'
import type { Automation } from '@/types/automations'
import PageHeader from '@/components/ui/PageHeader.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import AppIcon from '@/components/AppIcon.vue'
import TemplatePicker from '@/components/automations/TemplatePicker.vue'

/** Listado de automatizaciones; cada una se edita en el editor visual. */
const auth = useAuthStore()
const toasts = useToastStore()
const confirmDialog = useConfirmStore()
const router = useRouter()

const PER_PAGE = 50
const items = ref<Automation[]>([])
const page = ref(1)
const lastPage = ref(1)
const loadingMore = ref(false)
const loading = ref(true)
const failed = ref(false)
const picking = ref(false)

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const { data } = await http.get('/automations', { params: { per_page: PER_PAGE } })
    items.value = data.data
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
    const { data } = await http.get('/automations', { params: { per_page: PER_PAGE, page: page.value + 1 } })
    const known = new Set(items.value.map((a) => a.id))
    items.value.push(...(data.data as Automation[]).filter((a) => !known.has(a.id)))
    page.value += 1
    lastPage.value = data.meta?.last_page ?? page.value
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    loadingMore.value = false
  }
}

function create(template: string): void {
  picking.value = false
  router.push({ name: 'automation-edit', params: { automation: 'nueva' }, query: { plantilla: template } })
}

async function toggle(a: Automation): Promise<void> {
  try {
    await http.put(`/automations/${a.id}`, {
      name: a.name,
      trigger: a.trigger,
      trigger_config: a.trigger === 'rss.item_published' ? { feed_url: a.trigger_config?.feed_url ?? '' } : null,
      brand: a.brand,
      is_enabled: !a.is_enabled,
      flow: a.flow,
    })
    a.is_enabled = !a.is_enabled
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

async function remove(a: Automation): Promise<void> {
  const ok = await confirmDialog.ask({ title: 'Eliminar automatización', message: `Se eliminará «${a.name}» y su historial de ejecuciones (también las que estén en espera).`, confirmText: 'Eliminar', danger: true })
  if (!ok) return
  try {
    await http.delete(`/automations/${a.id}`)
    items.value = items.value.filter((x) => x.id !== a.id)
    toasts.success('Eliminada.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader title="Automatizaciones" description="Cuando ocurra algo, ejecuta acciones automáticamente: con condiciones, esperas y varios caminos.">
      <template #actions>
        <button v-if="auth.can('automations.create')" class="btn-primary text-sm" @click="picking = true">
          <AppIcon name="plus" :size="16" /> Nueva automatización
        </button>
      </template>
    </PageHeader>

    <div v-if="loading" class="card p-6"><div class="skeleton h-40 w-full" /></div>
    <ErrorState v-else-if="failed" @retry="load" />
    <EmptyState
      v-else-if="items.length === 0"
      icon="automations"
      title="Sin automatizaciones"
      description="Crea tu primera regla para ahorrar trabajo repetitivo: avisos, borradores desde tu blog, respuestas del inbox…"
    >
      <template v-if="auth.can('automations.create')" #action>
        <button class="btn-primary text-sm" @click="picking = true">Nueva automatización</button>
      </template>
    </EmptyState>

    <div v-else class="space-y-3">
      <div v-for="a in items" :key="a.id" class="card flex flex-wrap items-center justify-between gap-3 p-4">
        <RouterLink :to="{ name: 'automation-edit', params: { automation: a.id } }" class="group flex min-w-0 flex-1 items-center gap-3">
          <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-950/50">
            <AppIcon name="automations" :size="18" />
          </span>
          <span class="min-w-0">
            <span class="block truncate font-semibold text-slate-900 group-hover:text-brand-700 dark:text-white dark:group-hover:text-brand-300">{{ a.name }}</span>
            <span class="block truncate text-xs text-slate-400">
              {{ a.trigger_label }} · {{ a.steps_count }} paso(s)
              <template v-if="a.brand_name">· {{ a.brand_name }}</template>
              · {{ a.run_count }} ejecuciones
            </span>
            <span v-if="a.feed?.last_error" class="block truncate text-xs text-rose-600">Feed: {{ a.feed.last_error }}</span>
            <span v-else-if="a.feed?.title" class="block truncate text-xs text-slate-400">Feed: {{ a.feed.title }}</span>
          </span>
        </RouterLink>
        <div class="flex items-center gap-2">
          <label class="flex cursor-pointer items-center gap-2 text-xs text-slate-500">
            <input
              type="checkbox"
              class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
              :checked="a.is_enabled"
              :disabled="!auth.can('automations.update')"
              :aria-label="`${a.is_enabled ? 'Pausar' : 'Activar'} ${a.name}`"
              @change="toggle(a)"
            />
            {{ a.is_enabled ? 'Activa' : 'Pausada' }}
          </label>
          <RouterLink :to="{ name: 'automation-edit', params: { automation: a.id } }" class="btn-secondary text-xs">Editar</RouterLink>
          <button
            v-if="auth.can('automations.delete')"
            class="btn-ghost text-xs text-rose-600"
            :aria-label="`Eliminar ${a.name}`"
            :title="`Eliminar ${a.name}`"
            @click="remove(a)"
          >
            <AppIcon name="trash" :size="14" />
          </button>
        </div>
      </div>
      <div v-if="page < lastPage" class="flex justify-center pt-2">
        <button type="button" class="btn-secondary text-sm" :disabled="loadingMore" @click="loadMore">
          {{ loadingMore ? 'Cargando…' : 'Cargar más automatizaciones' }}
        </button>
      </div>
    </div>

    <TemplatePicker :open="picking" @close="picking = false" @choose="create" />
  </div>
</template>
