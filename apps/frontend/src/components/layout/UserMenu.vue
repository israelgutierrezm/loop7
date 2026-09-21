<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { onClickOutside } from '@vueuse/core'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import AppIcon from '@/components/AppIcon.vue'

const auth = useAuthStore()
const toasts = useToastStore()
const router = useRouter()
const open = ref(false)
const root = ref<HTMLElement | null>(null)

onClickOutside(root, () => (open.value = false))

function initials(name: string): string {
  return name
    .split(' ')
    .map((p) => p.charAt(0))
    .slice(0, 2)
    .join('')
    .toUpperCase()
}

async function logout(): Promise<void> {
  open.value = false
  await auth.logout()
  toasts.success('Sesión cerrada.')
  router.push('/login')
}
</script>

<template>
  <div ref="root" class="relative">
    <button
      class="flex items-center gap-2 rounded-full p-1 pr-2 transition hover:bg-slate-100 dark:hover:bg-slate-800"
      @click="open = !open"
    >
      <span class="grid h-8 w-8 place-items-center rounded-full bg-brand-600 text-xs font-bold text-white">
        {{ initials(auth.user?.name ?? '?') }}
      </span>
      <AppIcon name="chevron-down" :size="16" class="text-slate-400" />
    </button>

    <Transition
      enter-active-class="transition duration-100 ease-out"
      enter-from-class="scale-95 opacity-0"
      leave-active-class="transition duration-75 ease-in"
      leave-to-class="scale-95 opacity-0"
    >
      <div
        v-if="open"
        class="absolute right-0 z-30 mt-2 w-56 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-lg dark:border-slate-800 dark:bg-slate-900"
      >
        <div class="border-b border-slate-100 px-4 py-3 dark:border-slate-800">
          <p class="truncate text-sm font-semibold text-slate-800 dark:text-slate-100">
            {{ auth.user?.name }}
          </p>
          <p class="truncate text-xs text-slate-400">{{ auth.user?.email }}</p>
        </div>
        <div class="py-1">
          <RouterLink
            to="/app/profile"
            class="flex items-center gap-2 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800"
            @click="open = false"
          >
            <AppIcon name="user" :size="18" /> Mi perfil
          </RouterLink>
          <RouterLink
            v-if="auth.isPlatformAdmin"
            to="/platform"
            class="flex items-center gap-2 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800"
            @click="open = false"
          >
            <AppIcon name="shield" :size="18" /> Panel de plataforma
          </RouterLink>
        </div>
        <div class="border-t border-slate-100 py-1 dark:border-slate-800">
          <button
            class="flex w-full items-center gap-2 px-4 py-2 text-sm text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/30"
            @click="logout"
          >
            <AppIcon name="logout" :size="18" /> Cerrar sesión
          </button>
        </div>
      </div>
    </Transition>
  </div>
</template>
