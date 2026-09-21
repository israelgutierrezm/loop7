<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import AppIcon from '@/components/AppIcon.vue'

const auth = useAuthStore()
const router = useRouter()

const links = [
  { to: '/platform', label: 'Dashboard', icon: 'dashboard', exact: true },
  { to: '/platform/organizations', label: 'Organizaciones', icon: 'building', exact: false },
  { to: '/platform/users', label: 'Usuarios', icon: 'team', exact: false },
  { to: '/platform/gateways', label: 'Pasarelas', icon: 'billing', exact: false },
  { to: '/platform/social', label: 'Integraciones', icon: 'social', exact: false },
  { to: '/platform/jobs', label: 'Colas', icon: 'queue', exact: false },
]

async function logout(): Promise<void> {
  await auth.logout()
  router.push('/login')
}
</script>

<template>
  <div class="flex min-h-full flex-col bg-slate-100 dark:bg-slate-950">
    <header class="bg-slate-900 text-white">
      <div class="mx-auto flex h-16 max-w-7xl items-center gap-6 px-4 lg:px-8">
        <div class="flex items-center gap-2">
          <span class="grid h-8 w-8 place-items-center rounded-lg bg-brand-600 font-bold">L7</span>
          <span class="font-semibold">Plataforma</span>
          <span class="rounded-full bg-rose-500/20 px-2 py-0.5 text-[10px] font-bold uppercase text-rose-300">
            Superadmin
          </span>
        </div>

        <nav class="ml-4 hidden items-center gap-1 md:flex">
          <RouterLink
            v-for="link in links"
            :key="link.to"
            :to="link.to"
            :exact-active-class="link.exact ? '!bg-white/10 !text-white' : ''"
            active-class="bg-white/10 text-white"
            class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-slate-300 transition hover:bg-white/10 hover:text-white"
          >
            <AppIcon :name="link.icon" :size="18" /> {{ link.label }}
          </RouterLink>
        </nav>

        <div class="ml-auto flex items-center gap-3 text-sm">
          <RouterLink to="/app" class="flex items-center gap-1.5 text-slate-300 hover:text-white">
            <AppIcon name="chevron-left" :size="16" /> Volver a la app
          </RouterLink>
          <button class="flex items-center gap-1.5 text-slate-300 hover:text-white" @click="logout">
            <AppIcon name="logout" :size="16" /> Salir
          </button>
        </div>
      </div>
    </header>

    <main class="flex-1">
      <div class="mx-auto w-full max-w-7xl px-4 py-8 lg:px-8">
        <RouterView />
      </div>
    </main>
  </div>
</template>
