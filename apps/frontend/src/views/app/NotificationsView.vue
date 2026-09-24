<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import http from '@/services/http'
import { useNotificationsStore, type AppNotification } from '@/stores/notifications'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage } from '@/utils/errors'
import { dateTime, relativeTime } from '@/utils/format'
import PageHeader from '@/components/ui/PageHeader.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import Spinner from '@/components/ui/Spinner.vue'
import AppIcon from '@/components/AppIcon.vue'

const store = useNotificationsStore()
const toasts = useToastStore()
const router = useRouter()

const filter = ref<'all' | 'unread'>('all')
const items = ref<AppNotification[]>([])
const page = ref(1)
const lastPage = ref(1)
const loading = ref(true)
const loadingMore = ref(false)
const failed = ref(false)

const levelStyles: Record<AppNotification['level'], string> = {
  info: 'bg-sky-100 text-sky-600 dark:bg-sky-950/60 dark:text-sky-400',
  success: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400',
  warning: 'bg-amber-100 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400',
  danger: 'bg-rose-100 text-rose-600 dark:bg-rose-950/60 dark:text-rose-400',
}
const levelIcons: Record<AppNotification['level'], string> = { info: 'info', success: 'check', warning: 'alert', danger: 'alert' }

async function fetchPage(p: number): Promise<void> {
  const { data } = await http.get('/notifications', {
    params: { page: p, per_page: 20, unread: filter.value === 'unread' ? 1 : undefined },
  })
  items.value = p === 1 ? data.data : [...items.value, ...data.data]
  page.value = data.meta.current_page ?? p
  lastPage.value = data.meta.last_page ?? p
  store.unread = data.meta.unread ?? store.unread
}

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    await fetchPage(1)
  } catch {
    failed.value = true
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

async function open(n: AppNotification): Promise<void> {
  try {
    await store.markRead(n)
  } catch {
    /* no bloquea la navegación */
  }
  if (n.path) router.push(n.path)
}

async function markAll(): Promise<void> {
  try {
    await store.markAllRead()
    const now = new Date().toISOString()
    items.value.forEach((n) => (n.read_at ??= now))
    if (filter.value === 'unread') items.value = []
    toasts.success('Todo marcado como leído.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}

watch(filter, load)
onMounted(load)
</script>

<template>
  <div class="max-w-3xl">
    <PageHeader title="Notificaciones" description="Aprobaciones, resultados de publicación y avisos de tu organización.">
      <template #actions>
        <RouterLink to="/app/profile#notificaciones" class="btn-ghost text-sm">Preferencias de correo</RouterLink>
        <button class="btn-secondary text-sm" :disabled="store.unread === 0" @click="markAll">
          <AppIcon name="check" :size="16" /> Marcar todo como leído
        </button>
      </template>
    </PageHeader>

    <div class="mb-4 inline-flex rounded-lg border border-slate-200 bg-white p-1 text-sm dark:border-slate-800 dark:bg-slate-900" role="tablist">
      <button
        v-for="opt in ([['all', 'Todas'], ['unread', `Sin leer (${store.unread})`]] as const)"
        :key="opt[0]"
        role="tab"
        :aria-selected="filter === opt[0]"
        class="rounded-md px-3 py-1.5 font-medium transition"
        :class="filter === opt[0] ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'"
        @click="filter = opt[0]"
      >
        {{ opt[1] }}
      </button>
    </div>

    <div v-if="loading" class="card p-6"><div class="skeleton h-40 w-full" /></div>
    <ErrorState v-else-if="failed" @retry="load" />
    <EmptyState
      v-else-if="items.length === 0"
      icon="bell"
      :title="filter === 'unread' ? 'No tienes avisos sin leer' : 'Sin notificaciones'"
      description="Te avisaremos cuando haya contenido por aprobar, publicaciones con errores o novedades de tu suscripción."
    />
    <div v-else class="card divide-y divide-slate-100 overflow-hidden dark:divide-slate-800">
      <button
        v-for="n in items"
        :key="n.id"
        class="flex w-full gap-3 px-4 py-4 text-left transition hover:bg-slate-50 focus-visible:bg-slate-50 dark:hover:bg-slate-800/60 dark:focus-visible:bg-slate-800/60"
        @click="open(n)"
      >
        <span class="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-full" :class="levelStyles[n.level]">
          <AppIcon :name="levelIcons[n.level]" :size="18" />
        </span>
        <span class="min-w-0 flex-1">
          <span class="flex items-start justify-between gap-3">
            <span :class="n.read_at ? 'text-slate-700 dark:text-slate-200' : 'font-semibold text-slate-900 dark:text-white'">
              {{ n.title }}
            </span>
            <time class="shrink-0 text-xs text-slate-400" :datetime="n.created_at ?? undefined" :title="dateTime(n.created_at)">
              {{ relativeTime(n.created_at) }}
            </time>
          </span>
          <span class="mt-1 block text-sm text-slate-500 dark:text-slate-400">{{ n.body }}</span>
        </span>
        <span v-if="!n.read_at" class="mt-2 h-2 w-2 shrink-0 rounded-full bg-brand-600" aria-label="Sin leer" />
      </button>
    </div>

    <div v-if="!loading && page < lastPage" class="mt-4 text-center">
      <button class="btn-secondary text-sm" :disabled="loadingMore" @click="more">
        <Spinner v-if="loadingMore" :size="16" /> Cargar más
      </button>
    </div>
  </div>
</template>
