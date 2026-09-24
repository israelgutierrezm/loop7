<script setup lang="ts">
import { onBeforeUnmount, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { useToastStore } from '@/stores/toasts'
import { useNotificationsStore } from '@/stores/notifications'
import http from '@/services/http'
import AppLogo from '@/components/AppLogo.vue'
import AppIcon from '@/components/AppIcon.vue'
import OrgSwitcher from '@/components/layout/OrgSwitcher.vue'
import SidebarNav from '@/components/layout/SidebarNav.vue'
import TopBar from '@/components/layout/TopBar.vue'
import AppBanners from '@/components/layout/AppBanners.vue'

const auth = useAuthStore()
const ui = useUiStore()
const toasts = useToastStore()
const notifications = useNotificationsStore()
const router = useRouter()

// Los avisos son por organización: se reinicia el sondeo al cambiar de organización.
watch(
  () => auth.currentOrganization?.id,
  (id) => (id ? notifications.start() : notifications.stop()),
  { immediate: true },
)
onBeforeUnmount(() => notifications.stop())

async function stopImpersonation(): Promise<void> {
  try {
    await http.post('/platform/impersonate/stop')
    await auth.fetchMe()
    toasts.success('Impersonación finalizada.')
    router.push('/platform')
  } catch {
    toasts.error('No se pudo finalizar la impersonación.')
  }
}
</script>

<template>
  <div class="flex h-full bg-slate-50 dark:bg-slate-950">
    <!-- Sidebar escritorio -->
    <aside
      class="hidden shrink-0 flex-col border-r border-slate-200 bg-white transition-[width] duration-200 lg:flex dark:border-slate-800 dark:bg-slate-900"
      :class="ui.sidebarCollapsed ? 'w-20' : 'w-64'"
    >
      <div class="flex h-16 items-center border-b border-slate-200 px-4 dark:border-slate-800">
        <AppLogo :show-text="!ui.sidebarCollapsed" />
      </div>
      <div v-if="!ui.sidebarCollapsed" class="px-3 py-3">
        <OrgSwitcher />
      </div>
      <SidebarNav :collapsed="ui.sidebarCollapsed" />
    </aside>

    <!-- Drawer móvil -->
    <Transition
      enter-active-class="transition-opacity duration-200"
      enter-from-class="opacity-0"
      leave-active-class="transition-opacity duration-150"
      leave-to-class="opacity-0"
    >
      <div v-if="ui.mobileDrawerOpen" class="fixed inset-0 z-40 lg:hidden">
        <div class="absolute inset-0 bg-slate-900/50" @click="ui.closeMobileDrawer()" />
        <aside class="absolute inset-y-0 left-0 flex w-72 flex-col bg-white shadow-xl dark:bg-slate-900">
          <div class="flex h-16 items-center justify-between border-b border-slate-200 px-4 dark:border-slate-800">
            <AppLogo />
            <button
              class="grid h-9 w-9 place-items-center rounded-lg text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"
              aria-label="Cerrar menú"
              @click="ui.closeMobileDrawer()"
            >
              <AppIcon name="close" :size="20" />
            </button>
          </div>
          <div class="px-3 py-3">
            <OrgSwitcher />
          </div>
          <SidebarNav @navigate="ui.closeMobileDrawer()" />
        </aside>
      </div>
    </Transition>

    <!-- Columna principal -->
    <div class="flex min-w-0 flex-1 flex-col">
      <div
        v-if="auth.impersonating"
        class="flex items-center justify-between gap-3 bg-amber-500 px-4 py-2 text-sm font-medium text-amber-950"
      >
        <span class="flex items-center gap-2">
          <AppIcon name="shield" :size="16" />
          Estás impersonando a un usuario.
        </span>
        <button class="rounded-md bg-amber-950/10 px-2.5 py-1 font-semibold hover:bg-amber-950/20" @click="stopImpersonation">
          Finalizar
        </button>
      </div>

      <AppBanners />
      <TopBar />

      <main class="flex-1 overflow-y-auto">
        <div class="mx-auto w-full max-w-7xl px-4 py-6 lg:px-8">
          <RouterView v-slot="{ Component }">
            <Transition
              mode="out-in"
              enter-active-class="transition duration-150 ease-out"
              enter-from-class="translate-y-1 opacity-0"
              leave-active-class="transition duration-100 ease-in"
              leave-to-class="opacity-0"
            >
              <component :is="Component" />
            </Transition>
          </RouterView>
        </div>
      </main>
    </div>
  </div>
</template>
