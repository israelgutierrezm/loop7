<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import PageHeader from '@/components/ui/PageHeader.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import BrandPicker from '@/components/BrandPicker.vue'
import AppIcon from '@/components/AppIcon.vue'

interface CalendarItem { id: string; title: string; status: string; status_label: string; scheduled_at: string | null }

const auth = useAuthStore()
const brandId = ref<string | null>(auth.brands[0]?.id ?? null)
const cursor = ref(new Date())
const items = ref<CalendarItem[]>([])
const loading = ref(false)
const failed = ref(false)

const monthLabel = computed(() =>
  cursor.value.toLocaleDateString('es', { month: 'long', year: 'numeric' }),
)

const grouped = computed(() => {
  const map: Record<string, CalendarItem[]> = {}
  for (const item of items.value) {
    if (!item.scheduled_at) continue
    const day = new Date(item.scheduled_at).toLocaleDateString('es', { weekday: 'long', day: 'numeric', month: 'long' })
    ;(map[day] ??= []).push(item)
  }
  return map
})

async function load(): Promise<void> {
  if (!brandId.value) return
  loading.value = true
  failed.value = false
  const from = new Date(cursor.value.getFullYear(), cursor.value.getMonth(), 1).toISOString()
  const to = new Date(cursor.value.getFullYear(), cursor.value.getMonth() + 1, 0, 23, 59, 59).toISOString()
  try {
    const { data } = await http.get(`/brands/${brandId.value}/calendar`, { params: { from, to } })
    items.value = data.data
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

function shift(months: number): void {
  cursor.value = new Date(cursor.value.getFullYear(), cursor.value.getMonth() + months, 1)
  load()
}

watch(brandId, load)
onMounted(load)
</script>

<template>
  <div>
    <PageHeader title="Calendario" description="Contenido programado por fecha.">
      <template #actions>
        <BrandPicker v-model="brandId" />
      </template>
    </PageHeader>

    <div class="mb-4 flex items-center justify-between">
      <div class="flex items-center gap-2">
        <button class="btn-ghost px-2" @click="shift(-1)"><AppIcon name="chevron-left" :size="18" /></button>
        <span class="min-w-40 text-center font-semibold capitalize text-slate-800 dark:text-slate-100">{{ monthLabel }}</span>
        <button class="btn-ghost px-2" @click="shift(1)"><AppIcon name="chevron-right" :size="18" /></button>
      </div>
    </div>

    <EmptyState v-if="!brandId" icon="brands" title="Crea una marca primero" />
    <div v-else-if="loading" class="card p-6"><div v-for="n in 4" :key="n" class="skeleton my-2 h-10 w-full" /></div>
    <ErrorState v-else-if="failed" @retry="load" />
    <EmptyState v-else-if="items.length === 0" icon="calendar" title="Nada programado" description="No hay contenido programado este mes." />

    <div v-else class="space-y-5">
      <div v-for="(dayItems, day) in grouped" :key="day">
        <p class="mb-2 text-sm font-semibold capitalize text-slate-500">{{ day }}</p>
        <div class="card divide-y divide-slate-100 dark:divide-slate-800">
          <RouterLink
            v-for="item in dayItems"
            :key="item.id"
            :to="`/app/content/${item.id}`"
            class="flex items-center justify-between px-5 py-3 transition hover:bg-slate-50 dark:hover:bg-slate-800/50"
          >
            <span class="flex items-center gap-2 text-sm">
              <span class="text-slate-400">{{ new Date(item.scheduled_at!).toLocaleTimeString('es', { hour: '2-digit', minute: '2-digit' }) }}</span>
              <span class="font-medium text-slate-800 dark:text-slate-100">{{ item.title }}</span>
            </span>
            <span class="rounded-full bg-brand-100 px-2 py-0.5 text-xs font-semibold text-brand-700">{{ item.status_label }}</span>
          </RouterLink>
        </div>
      </div>
    </div>
  </div>
</template>
