<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { dateTime, limit, relativeTime } from '@/utils/format'
import PageHeader from '@/components/ui/PageHeader.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import StatusBadge, { type BadgeTone } from '@/components/ui/StatusBadge.vue'
import AppIcon from '@/components/AppIcon.vue'
import ProviderIcon from '@/components/social/ProviderIcon.vue'

interface Upcoming { id: string; title: string; brand: string | null; scheduled_at: string | null; providers: string[] }
interface Attention { id: string; title: string; brand: string | null; status: string; status_label: string; updated_at: string | null }
interface Dashboard {
  onboarding: { has_brand: boolean; has_connection: boolean; has_content: boolean; has_published: boolean; has_team: boolean }
  usage: Record<string, number>
  content?: {
    in_review: number
    ready: number
    scheduled_next_7_days: number
    published_7_days: number
    failed_7_days: number
    upcoming: Upcoming[]
    attention: Attention[]
  }
  social?: { connected: number; needs_attention: number }
  team?: { members: number; pending_invitations: number }
}

const auth = useAuthStore()
const data = ref<Dashboard | null>(null)
const loading = ref(true)
const failed = ref(false)

const firstName = computed(() => auth.user?.name?.split(' ')[0] ?? '')

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const res = await http.get('/dashboard')
    data.value = res.data.data
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

// Puesta en marcha: pasos reales de la organización; cada CTA sólo si el rol puede hacerlo.
const steps = computed(() => {
  const o = data.value?.onboarding
  if (!o) return []
  return [
    { done: o.has_brand, title: 'Crea tu primera marca', text: 'La identidad de tu negocio: tono, públicos y productos.', to: '/app/brands', cta: 'Ir a marcas', can: auth.can('brands.create') },
    { done: o.has_connection, title: 'Conecta tus redes sociales', text: 'Facebook e Instagram, con permisos oficiales de Meta.', to: '/app/social', cta: 'Conectar', can: auth.can('social_accounts.connect') },
    { done: o.has_content, title: 'Crea tu primer contenido', text: 'Escríbelo tú o pídeselo al asistente de IA.', to: '/app/content', cta: 'Crear contenido', can: auth.can('content.create') },
    { done: o.has_team, title: 'Invita a tu equipo', text: 'Cada persona con su rol: creación, aprobación, publicación…', to: '/app/team', cta: 'Invitar', can: auth.can('members.invite') },
    { done: o.has_published, title: 'Publica por primera vez', text: 'Programa o publica al momento en todas tus redes.', to: '/app/calendar', cta: 'Ver calendario', can: auth.can('content.view') },
  ]
})
const doneSteps = computed(() => steps.value.filter((s) => s.done).length)
const showOnboarding = computed(() => steps.value.length > 0 && doneSteps.value < steps.value.length)

const kpis = computed(() => {
  const c = data.value?.content
  if (!c) return []
  return [
    { label: 'Programadas (7 días)', value: c.scheduled_next_7_days, icon: 'calendar', to: '/app/calendar', hint: `${c.ready} aprobada(s) sin programar` },
    { label: 'Pendientes de aprobación', value: c.in_review, icon: 'check', to: '/app/content?status=in_review', hint: auth.can('content.approve') ? 'Te toca revisarlas' : 'En revisión' },
    { label: 'Publicadas (7 días)', value: c.published_7_days, icon: 'social', to: '/app/analytics', hint: 'Publicaciones por red' },
    { label: 'Con errores (7 días)', value: c.failed_7_days, icon: 'alert', to: '/app/content?status=failed', hint: c.failed_7_days ? 'Revisa y vuelve a intentar' : 'Todo en orden' },
  ]
})

const usageRows = computed(() => {
  const usage = data.value?.usage ?? {}
  const rows = [
    { key: 'scheduled_posts.month', label: 'Publicaciones este mes' },
    { key: 'ai_credits.month', label: 'Créditos de IA este mes' },
    { key: 'brands.max', label: 'Marcas' },
    { key: 'social_accounts.max', label: 'Cuentas sociales' },
  ]
  return rows.map((r) => {
    const max = auth.entitlements[r.key]
    const used = usage[r.key] ?? 0
    const unlimited = max === -1
    const numericMax = typeof max === 'number' ? max : 0
    const pct = unlimited || numericMax <= 0 ? 0 : Math.min(100, Math.round((used / numericMax) * 100))
    return { ...r, used, max: limit(max), unlimited, pct }
  })
})

const attentionTone: Record<string, BadgeTone> = { failed: 'danger', partial: 'warning', changes_requested: 'warning' }

watch(() => auth.currentOrganization?.id, load)
onMounted(load)
</script>

<template>
  <div>
    <PageHeader :title="`Hola, ${firstName} 👋`" :description="`Esto es lo que pasa en ${auth.currentOrganization?.name ?? 'tu organización'}.`">
      <template #actions>
        <RouterLink v-if="auth.can('content.create')" to="/app/content" class="btn-primary text-sm">
          <AppIcon name="plus" :size="16" /> Crear contenido
        </RouterLink>
      </template>
    </PageHeader>

    <div v-if="loading" class="space-y-4">
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div v-for="n in 4" :key="n" class="skeleton h-24 w-full rounded-xl" />
      </div>
      <div class="skeleton h-64 w-full rounded-xl" />
    </div>
    <ErrorState v-else-if="failed || !data" @retry="load" />

    <div v-else class="space-y-6">
      <!-- Puesta en marcha -->
      <section v-if="showOnboarding" class="card p-6" aria-labelledby="onb-title">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 id="onb-title" class="text-lg font-semibold text-slate-900 dark:text-white">Primeros pasos</h2>
            <p class="text-sm text-slate-500">{{ doneSteps }} de {{ steps.length }} completados</p>
          </div>
          <div class="h-2 w-full max-w-xs overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800" role="progressbar" :aria-valuenow="doneSteps" aria-valuemin="0" :aria-valuemax="steps.length">
            <div class="h-full rounded-full bg-brand-600 transition-all" :style="{ width: `${(doneSteps / steps.length) * 100}%` }" />
          </div>
        </div>
        <ul class="mt-5 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
          <li
            v-for="step in steps"
            :key="step.title"
            class="flex items-start gap-3 rounded-lg border p-4"
            :class="step.done ? 'border-emerald-100 bg-emerald-50/50 dark:border-emerald-900/40 dark:bg-emerald-950/20' : 'border-slate-100 dark:border-slate-800'"
          >
            <span
              class="grid h-8 w-8 shrink-0 place-items-center rounded-full"
              :class="step.done ? 'bg-emerald-500 text-white' : 'bg-slate-100 text-slate-400 dark:bg-slate-800'"
            >
              <AppIcon :name="step.done ? 'check' : 'sparkles'" :size="16" />
            </span>
            <div class="min-w-0 flex-1">
              <p class="text-sm font-medium" :class="step.done ? 'text-slate-500 line-through' : 'text-slate-800 dark:text-slate-100'">{{ step.title }}</p>
              <p v-if="!step.done" class="mt-0.5 text-xs text-slate-500">{{ step.text }}</p>
              <RouterLink v-if="!step.done && step.can" :to="step.to" class="mt-2 inline-flex text-xs font-semibold text-brand-600 hover:underline dark:text-brand-400">
                {{ step.cta }} →
              </RouterLink>
            </div>
          </li>
        </ul>
      </section>

      <!-- Indicadores -->
      <div v-if="kpis.length" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <RouterLink
          v-for="k in kpis"
          :key="k.label"
          :to="k.to"
          class="card flex items-center gap-4 p-5 transition hover:border-brand-200 hover:shadow-md focus-visible:ring-2 focus-visible:ring-brand-500 dark:hover:border-brand-800"
        >
          <span
            class="grid h-11 w-11 shrink-0 place-items-center rounded-xl"
            :class="k.icon === 'alert' && k.value > 0 ? 'bg-rose-50 text-rose-600 dark:bg-rose-950/50' : 'bg-brand-50 text-brand-600 dark:bg-brand-950/50'"
          >
            <AppIcon :name="k.icon" :size="22" />
          </span>
          <span class="min-w-0">
            <span class="block truncate text-sm text-slate-500">{{ k.label }}</span>
            <span class="block text-2xl font-bold text-slate-900 dark:text-white">{{ k.value }}</span>
            <span class="block truncate text-xs text-slate-400">{{ k.hint }}</span>
          </span>
        </RouterLink>
      </div>

      <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
          <!-- Próximas publicaciones -->
          <section v-if="data.content" class="card" aria-labelledby="up-title">
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4 dark:border-slate-800">
              <h2 id="up-title" class="font-semibold text-slate-900 dark:text-white">Próximas publicaciones</h2>
              <RouterLink to="/app/calendar" class="text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">Calendario</RouterLink>
            </div>
            <p v-if="data.content.upcoming.length === 0" class="px-5 py-8 text-center text-sm text-slate-500">
              No hay nada programado. {{ data.content.ready ? `Tienes ${data.content.ready} contenido(s) aprobado(s) listo(s) para programar.` : '' }}
            </p>
            <ul v-else class="divide-y divide-slate-100 dark:divide-slate-800">
              <li v-for="u in data.content.upcoming" :key="u.id">
                <RouterLink :to="`/app/content/${u.id}`" class="flex items-center gap-4 px-5 py-3 transition hover:bg-slate-50 dark:hover:bg-slate-800/50">
                  <span class="w-28 shrink-0 text-xs font-medium text-slate-500">{{ dateTime(u.scheduled_at) }}</span>
                  <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-medium text-slate-800 dark:text-slate-100">{{ u.title }}</span>
                    <span class="block truncate text-xs text-slate-400">{{ u.brand }}</span>
                  </span>
                  <span class="flex shrink-0 gap-1">
                    <ProviderIcon v-for="p in u.providers" :key="p" :provider="p" :size="22" />
                  </span>
                </RouterLink>
              </li>
            </ul>
          </section>

          <!-- Requiere atención -->
          <section v-if="data.content && data.content.attention.length" class="card" aria-labelledby="att-title">
            <div class="border-b border-slate-100 px-5 py-4 dark:border-slate-800">
              <h2 id="att-title" class="font-semibold text-slate-900 dark:text-white">Requiere tu atención</h2>
            </div>
            <ul class="divide-y divide-slate-100 dark:divide-slate-800">
              <li v-for="a in data.content.attention" :key="a.id">
                <RouterLink :to="`/app/content/${a.id}`" class="flex items-center gap-3 px-5 py-3 transition hover:bg-slate-50 dark:hover:bg-slate-800/50">
                  <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-medium text-slate-800 dark:text-slate-100">{{ a.title }}</span>
                    <span class="block truncate text-xs text-slate-400">{{ a.brand }} · {{ relativeTime(a.updated_at) }}</span>
                  </span>
                  <StatusBadge :tone="attentionTone[a.status] ?? 'neutral'" dot>{{ a.status_label }}</StatusBadge>
                </RouterLink>
              </li>
            </ul>
          </section>
        </div>

        <div class="space-y-6">
          <!-- Redes -->
          <section v-if="data.social" class="card p-5" aria-labelledby="soc-title">
            <div class="flex items-center justify-between">
              <h2 id="soc-title" class="font-semibold text-slate-900 dark:text-white">Cuentas sociales</h2>
              <RouterLink to="/app/social" class="text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">Gestionar</RouterLink>
            </div>
            <p class="mt-3 text-3xl font-bold text-slate-900 dark:text-white">{{ data.social.connected }}</p>
            <p class="text-sm text-slate-500">conectada(s)</p>
            <p
              v-if="data.social.needs_attention"
              class="mt-3 flex items-center gap-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-950/40 dark:text-amber-200"
            >
              <AppIcon name="alert" :size="16" /> {{ data.social.needs_attention }} requiere(n) reconexión
            </p>
          </section>

          <!-- Uso del plan -->
          <section class="card p-5" aria-labelledby="use-title">
            <div class="flex items-center justify-between">
              <h2 id="use-title" class="font-semibold text-slate-900 dark:text-white">Uso del plan</h2>
              <RouterLink v-if="auth.can('billing.view')" to="/app/billing" class="text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">
                {{ auth.subscription?.plan_name ?? 'Facturación' }}
              </RouterLink>
            </div>
            <ul class="mt-4 space-y-3">
              <li v-for="r in usageRows" :key="r.key">
                <div class="flex justify-between text-xs">
                  <span class="text-slate-600 dark:text-slate-300">{{ r.label }}</span>
                  <span class="font-medium text-slate-800 dark:text-slate-100">{{ r.used }} / {{ r.max }}</span>
                </div>
                <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                  <div
                    class="h-full rounded-full"
                    :class="r.pct >= 90 ? 'bg-rose-500' : r.pct >= 70 ? 'bg-amber-500' : 'bg-brand-600'"
                    :style="{ width: r.unlimited ? '0%' : `${r.pct}%` }"
                  />
                </div>
              </li>
            </ul>
          </section>

          <!-- Equipo -->
          <section v-if="data.team" class="card p-5" aria-labelledby="team-title">
            <div class="flex items-center justify-between">
              <h2 id="team-title" class="font-semibold text-slate-900 dark:text-white">Equipo</h2>
              <RouterLink to="/app/team" class="text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">Ver equipo</RouterLink>
            </div>
            <p class="mt-3 text-sm text-slate-600 dark:text-slate-300">
              <strong class="text-slate-900 dark:text-white">{{ data.team.members }}</strong> miembro(s)
              <span v-if="data.team.pending_invitations"> · {{ data.team.pending_invitations }} invitación(es) pendiente(s)</span>
            </p>
          </section>
        </div>
      </div>
    </div>
  </div>
</template>
