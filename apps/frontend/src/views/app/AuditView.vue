<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue'
import { useDebounceFn } from '@vueuse/core'
import http from '@/services/http'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage } from '@/utils/errors'
import { dateTime } from '@/utils/format'
import PageHeader from '@/components/ui/PageHeader.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import Spinner from '@/components/ui/Spinner.vue'
import AppIcon from '@/components/AppIcon.vue'

interface AuditEntry {
  id: string
  action: string
  description: string | null
  properties: Record<string, unknown> | null
  actor: { name: string; email: string } | null
  ip_address: string | null
  created_at: string | null
}

const GROUPS = [
  { value: '', label: 'Todas las acciones' },
  { value: 'auth.', label: 'Accesos' },
  { value: 'organization.', label: 'Organización' },
  { value: 'member.', label: 'Equipo' },
  { value: 'brand.', label: 'Marcas' },
  { value: 'content.', label: 'Contenido' },
  { value: 'campaign.', label: 'Campañas' },
  { value: 'media.', label: 'Biblioteca' },
  { value: 'social.', label: 'Redes sociales' },
  { value: 'inbox.', label: 'Inbox' },
  { value: 'ai.', label: 'IA' },
  { value: 'subscription.', label: 'Suscripción' },
  { value: 'billing.', label: 'Facturación' },
  { value: 'superadmin.', label: 'Soporte de la plataforma' },
]

const toasts = useToastStore()
const filters = reactive({ action: '', q: '' })
const logs = ref<AuditEntry[]>([])
const page = ref(1)
const lastPage = ref(1)
const loading = ref(true)
const loadingMore = ref(false)
const failed = ref(false)
const expanded = ref<string | null>(null)

async function fetchPage(p: number): Promise<void> {
  const { data } = await http.get('/audit-logs', {
    params: { page: p, per_page: 30, action: filters.action || undefined, q: filters.q.trim() || undefined },
  })
  logs.value = p === 1 ? data.data : [...logs.value, ...data.data]
  page.value = data.meta.current_page ?? p
  lastPage.value = data.meta.last_page ?? p
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

function details(log: AuditEntry): [string, string][] {
  return Object.entries(log.properties ?? {}).map(([key, value]) => [
    key,
    typeof value === 'object' && value !== null ? JSON.stringify(value) : String(value ?? '—'),
  ])
}

const debouncedLoad = useDebounceFn(load, 350)
watch(() => filters.action, load)
watch(() => filters.q, () => debouncedLoad())
onMounted(load)
</script>

<template>
  <div>
    <PageHeader title="Auditoría" description="Registro inmutable de las acciones realizadas en tu organización." />

    <div class="mb-4 flex flex-wrap items-center gap-2">
      <label for="au-action" class="sr-only">Tipo de acción</label>
      <select id="au-action" v-model="filters.action" class="input w-auto">
        <option v-for="g in GROUPS" :key="g.value" :value="g.value">{{ g.label }}</option>
      </select>
      <div class="relative min-w-[12rem] flex-1">
        <AppIcon name="search" :size="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
        <label for="au-search" class="sr-only">Buscar por persona</label>
        <input id="au-search" v-model="filters.q" type="search" class="input pl-9" placeholder="Buscar por nombre o correo…" />
      </div>
    </div>

    <div v-if="loading" class="card p-6">
      <div v-for="n in 6" :key="n" class="skeleton my-2 h-8 w-full" />
    </div>

    <ErrorState v-else-if="failed" @retry="load" />

    <EmptyState
      v-else-if="logs.length === 0"
      icon="shield"
      :title="filters.action || filters.q ? 'Sin eventos con estos filtros' : 'Sin eventos'"
      description="Aquí aparecen accesos, cambios de equipo, publicaciones, pagos y más."
    />

    <template v-else>
      <div class="card overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-left text-sm">
            <thead class="border-b border-slate-100 text-xs uppercase tracking-wide text-slate-400 dark:border-slate-800">
              <tr>
                <th scope="col" class="px-5 py-3 font-semibold">Acción</th>
                <th scope="col" class="px-5 py-3 font-semibold">Persona</th>
                <th scope="col" class="px-5 py-3 font-semibold">IP</th>
                <th scope="col" class="px-5 py-3 font-semibold">Fecha</th>
                <th scope="col" class="px-5 py-3"><span class="sr-only">Detalle</span></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
              <template v-for="log in logs" :key="log.id">
                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                  <td class="px-5 py-3">
                    <code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-700 dark:bg-slate-800 dark:text-slate-300">{{ log.action }}</code>
                    <span v-if="log.properties?.impersonated_by" class="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700">SOPORTE</span>
                  </td>
                  <td class="px-5 py-3 text-slate-600 dark:text-slate-300">
                    {{ log.actor?.name ?? 'Sistema' }}
                    <span v-if="log.actor" class="block text-xs text-slate-400">{{ log.actor.email }}</span>
                  </td>
                  <td class="px-5 py-3 text-slate-400">{{ log.ip_address ?? '—' }}</td>
                  <td class="whitespace-nowrap px-5 py-3 text-slate-400">{{ dateTime(log.created_at) }}</td>
                  <td class="px-5 py-3 text-right">
                    <button
                      v-if="details(log).length"
                      class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400"
                      :aria-expanded="expanded === log.id"
                      @click="expanded = expanded === log.id ? null : log.id"
                    >
                      {{ expanded === log.id ? 'Ocultar' : 'Detalle' }}
                    </button>
                  </td>
                </tr>
                <tr v-if="expanded === log.id" class="bg-slate-50/70 dark:bg-slate-800/30">
                  <td colspan="5" class="px-5 py-3">
                    <dl class="grid grid-cols-1 gap-x-6 gap-y-1 text-xs sm:grid-cols-2">
                      <div v-for="[k, v] in details(log)" :key="k" class="flex gap-2">
                        <dt class="shrink-0 font-medium text-slate-500">{{ k }}</dt>
                        <dd class="break-all text-slate-700 dark:text-slate-300">{{ v }}</dd>
                      </div>
                    </dl>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </div>
      <div v-if="page < lastPage" class="mt-4 text-center">
        <button class="btn-secondary text-sm" :disabled="loadingMore" @click="more">
          <Spinner v-if="loadingMore" :size="16" /> Cargar más
        </button>
      </div>
    </template>
  </div>
</template>
