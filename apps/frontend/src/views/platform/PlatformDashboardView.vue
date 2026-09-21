<script setup lang="ts">
import { onMounted, ref } from 'vue'
import http from '@/services/http'
import StatCard from '@/components/StatCard.vue'
import ErrorState from '@/components/ui/ErrorState.vue'

interface Stats {
  organizations: { total: number; active: number; suspended: number }
  users: { total: number; platform_admins: number; new_last_7_days: number }
  brands: { total: number }
  audit: { events_today: number }
}

const stats = ref<Stats | null>(null)
const loading = ref(true)
const failed = ref(false)

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const { data } = await http.get('/platform/dashboard')
    stats.value = data.data
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <h1 class="mb-6 text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
      Salud de la plataforma
    </h1>

    <div v-if="loading" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <div v-for="n in 4" :key="n" class="card p-5"><div class="skeleton h-14 w-full" /></div>
    </div>

    <ErrorState v-else-if="failed" @retry="load" />

    <div v-else-if="stats" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <StatCard label="Organizaciones" :value="stats.organizations.total" icon="building" :hint="`${stats.organizations.active} activas`" />
      <StatCard label="Usuarios" :value="stats.users.total" icon="team" :hint="`+${stats.users.new_last_7_days} en 7 días`" />
      <StatCard label="Marcas" :value="stats.brands.total" icon="brands" />
      <StatCard label="Eventos hoy" :value="stats.audit.events_today" icon="shield" hint="Auditoría" />
    </div>
  </div>
</template>
