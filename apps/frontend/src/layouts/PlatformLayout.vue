<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import AppIcon from '@/components/AppIcon.vue'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()
const drawerOpen = ref(false)

const groups = [
  { label: '', items: [{ to: '/platform', label: 'Dashboard', icon: 'dashboard', exact: true }] },
  {
    label: 'Clientes',
    items: [
      { to: '/platform/organizations', label: 'Organizaciones', icon: 'building' },
      { to: '/platform/users', label: 'Usuarios', icon: 'team' },
      { to: '/platform/subscriptions', label: 'Suscripciones', icon: 'refresh' },
    ],
  },
  {
    label: 'Ingresos',
    items: [
      { to: '/platform/plans', label: 'Planes', icon: 'sparkles' },
      { to: '/platform/payments', label: 'Pagos y facturas', icon: 'billing' },
      { to: '/platform/gateways', label: 'Pasarelas', icon: 'key' },
    ],
  },
  {
    label: 'Integraciones',
    items: [
      { to: '/platform/social', label: 'Redes sociales', icon: 'social' },
      { to: '/platform/ai-providers', label: 'Proveedores de IA', icon: 'ai' },
    ],
  },
  {
    label: 'Sistema',
    items: [
      { to: '/platform/jobs', label: 'Colas', icon: 'queue' },
      { to: '/platform/audit', label: 'Auditoría', icon: 'shield' },
      { to: '/platform/settings', label: 'Configuración', icon: 'settings' },
    ],
  },
] as const

watch(() => route.fullPath, () => (drawerOpen.value = false))

async function logout(): Promise<void> {
  await auth.logout()
  router.push('/login')
}
</script>

<template>
  <div class="flex h-full bg-slate-100 dark:bg-slate-950">
    <!-- Navegación (fija en escritorio, cajón en móvil) -->
    <div
      v-if="drawerOpen"
      class="fixed inset-0 z-40 bg-slate-900/50 lg:hidden"
      aria-hidden="true"
      @click="drawerOpen = false"
    />
    <aside
      class="fixed inset-y-0 left-0 z-50 flex w-64 flex-col bg-slate-900 text-slate-300 transition-transform duration-200 lg:static lg:translate-x-0"
      :class="drawerOpen ? 'translate-x-0' : '-translate-x-full'"
      aria-label="Navegación de plataforma"
    >
      <div class="flex h-16 items-center gap-2 border-b border-white/10 px-4">
        <span class="grid h-8 w-8 place-items-center rounded-lg bg-brand-600 text-sm font-bold text-white">L7</span>
        <span class="font-semibold text-white">Plataforma</span>
        <span class="rounded-full bg-rose-500/20 px-2 py-0.5 text-[10px] font-bold uppercase text-rose-300">Superadmin</span>
        <button
          type="button"
          class="ml-auto grid h-8 w-8 place-items-center rounded-lg hover:bg-white/10 lg:hidden"
          aria-label="Cerrar menú"
          @click="drawerOpen = false"
        >
          <AppIcon name="close" :size="18" />
        </button>
      </div>

      <nav class="flex-1 space-y-5 overflow-y-auto px-3 py-4">
        <div v-for="group in groups" :key="group.label">
          <p v-if="group.label" class="mb-1 px-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ group.label }}</p>
          <ul class="space-y-0.5">
            <li v-for="item in group.items" :key="item.to">
              <RouterLink
                :to="item.to"
                class="flex items-center gap-3 rounded-lg px-2.5 py-2 text-sm font-medium transition hover:bg-white/10 hover:text-white"
                :active-class="'exact' in item ? '' : '!bg-white/10 !text-white'"
                :exact-active-class="'exact' in item ? '!bg-white/10 !text-white' : ''"
              >
                <AppIcon :name="item.icon" :size="18" /> {{ item.label }}
              </RouterLink>
            </li>
          </ul>
        </div>
      </nav>

      <div class="space-y-1 border-t border-white/10 p-3 text-sm">
        <RouterLink to="/app" class="flex items-center gap-2 rounded-lg px-2.5 py-2 hover:bg-white/10 hover:text-white">
          <AppIcon name="chevron-left" :size="16" /> Volver a la app
        </RouterLink>
        <button type="button" class="flex w-full items-center gap-2 rounded-lg px-2.5 py-2 hover:bg-white/10 hover:text-white" @click="logout">
          <AppIcon name="logout" :size="16" /> Cerrar sesión
        </button>
      </div>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
      <header class="flex h-14 items-center gap-3 border-b border-slate-200 bg-white px-4 lg:hidden dark:border-slate-800 dark:bg-slate-900">
        <button
          type="button"
          class="grid h-9 w-9 place-items-center rounded-lg text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800"
          aria-label="Abrir menú"
          :aria-expanded="drawerOpen ? 'true' : 'false'"
          @click="drawerOpen = true"
        >
          <AppIcon name="menu" :size="20" />
        </button>
        <span class="font-semibold text-slate-900 dark:text-white">Plataforma</span>
      </header>

      <main class="flex-1 overflow-y-auto">
        <div class="mx-auto w-full max-w-7xl px-4 py-6 lg:px-8 lg:py-8">
          <RouterView />
        </div>
      </main>
    </div>
  </div>
</template>
