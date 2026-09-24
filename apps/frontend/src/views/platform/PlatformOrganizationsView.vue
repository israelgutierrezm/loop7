<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import http from '@/services/http'
import { date } from '@/utils/format'
import PageHeader from '@/components/ui/PageHeader.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'

interface PlatformOrg {
  id: string
  name: string
  slug: string
  status: string
  billing_email: string | null
  members_count: number | null
  brands_count: number | null
  created_at: string | null
}

const orgs = ref<PlatformOrg[]>([])
const loading = ref(true)
const failed = ref(false)
const search = ref('')
const status = ref('')
const page = ref(1)
const lastPage = ref(1)

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const { data } = await http.get('/platform/organizations', {
      params: { q: search.value || undefined, status: status.value || undefined, page: page.value },
    })
    orgs.value = data.data
    lastPage.value = data.meta.last_page ?? 1
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

watch(status, () => {
  page.value = 1
  load()
})
onMounted(load)
</script>

<template>
  <div>
    <PageHeader title="Organizaciones" description="Clientes de la plataforma. Abre una organización para gestionar su plan, límites, add-ons y estado." />

    <div class="mb-4 flex flex-wrap gap-3">
      <label for="org-search" class="sr-only">Buscar</label>
      <input id="org-search" v-model="search" type="search" placeholder="Buscar por nombre o correo…" class="input w-72" @keyup.enter="page = 1; load()" />
      <label for="org-status" class="sr-only">Estado</label>
      <select id="org-status" v-model="status" class="input w-auto">
        <option value="">Todas</option>
        <option value="active">Activas</option>
        <option value="suspended">Suspendidas</option>
      </select>
    </div>

    <div v-if="loading" class="card p-6"><div v-for="n in 6" :key="n" class="skeleton my-2 h-8 w-full" /></div>
    <ErrorState v-else-if="failed" @retry="load" />
    <EmptyState v-else-if="orgs.length === 0" icon="building" title="Sin organizaciones" />

    <div v-else class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
          <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50">
            <tr>
              <th scope="col" class="px-5 py-3 font-medium">Organización</th>
              <th scope="col" class="px-5 py-3 font-medium">Estado</th>
              <th scope="col" class="px-5 py-3 font-medium">Miembros</th>
              <th scope="col" class="px-5 py-3 font-medium">Marcas</th>
              <th scope="col" class="px-5 py-3 font-medium">Alta</th>
              <th scope="col" class="px-5 py-3"><span class="sr-only">Acciones</span></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
            <tr v-for="org in orgs" :key="org.id" class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
              <td class="px-5 py-3">
                <RouterLink :to="`/platform/organizations/${org.id}`" class="font-medium text-slate-900 hover:text-brand-600 dark:text-white">
                  {{ org.name }}
                </RouterLink>
                <p class="text-xs text-slate-400">{{ org.billing_email ?? org.slug }}</p>
              </td>
              <td class="px-5 py-3">
                <StatusBadge :tone="org.status === 'active' ? 'success' : 'danger'" dot>{{ org.status === 'active' ? 'Activa' : 'Suspendida' }}</StatusBadge>
              </td>
              <td class="px-5 py-3 text-slate-500">{{ org.members_count ?? '—' }}</td>
              <td class="px-5 py-3 text-slate-500">{{ org.brands_count ?? '—' }}</td>
              <td class="px-5 py-3 text-slate-500">{{ date(org.created_at) }}</td>
              <td class="px-5 py-3 text-right">
                <RouterLink :to="`/platform/organizations/${org.id}`" class="btn-ghost px-3 py-1 text-xs">Gestionar</RouterLink>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="lastPage > 1" class="flex items-center justify-end gap-2 border-t border-slate-100 px-5 py-3 text-sm dark:border-slate-800">
        <button type="button" class="btn-ghost px-3 py-1 text-xs" :disabled="page <= 1" @click="page--; load()">Anterior</button>
        <span class="text-slate-500">Página {{ page }} de {{ lastPage }}</span>
        <button type="button" class="btn-ghost px-3 py-1 text-xs" :disabled="page >= lastPage" @click="page++; load()">Siguiente</button>
      </div>
    </div>
  </div>
</template>
