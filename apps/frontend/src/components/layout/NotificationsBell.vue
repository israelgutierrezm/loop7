<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { onClickOutside, onKeyStroke } from '@vueuse/core'
import { useNotificationsStore, type AppNotification } from '@/stores/notifications'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage } from '@/utils/errors'
import { relativeTime } from '@/utils/format'
import AppIcon from '@/components/AppIcon.vue'
import Spinner from '@/components/ui/Spinner.vue'

const store = useNotificationsStore()
const toasts = useToastStore()
const router = useRouter()

const open = ref(false)
const root = ref<HTMLElement | null>(null)
onClickOutside(root, () => (open.value = false))
onKeyStroke('Escape', () => (open.value = false))

const badge = computed(() => (store.unread > 99 ? '99+' : String(store.unread)))

const levelStyles: Record<AppNotification['level'], string> = {
  info: 'bg-sky-100 text-sky-600 dark:bg-sky-950/60 dark:text-sky-400',
  success: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400',
  warning: 'bg-amber-100 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400',
  danger: 'bg-rose-100 text-rose-600 dark:bg-rose-950/60 dark:text-rose-400',
}
const levelIcons: Record<AppNotification['level'], string> = {
  info: 'info',
  success: 'check',
  warning: 'alert',
  danger: 'alert',
}

async function toggle(): Promise<void> {
  open.value = !open.value
  if (open.value) {
    try {
      await store.loadRecent()
    } catch (e) {
      toasts.error(apiErrorMessage(e))
    }
  }
}

async function openNotification(n: AppNotification): Promise<void> {
  open.value = false
  try {
    await store.markRead(n)
  } catch {
    /* marcar como leído no debe impedir la navegación */
  }
  if (n.path) router.push(n.path)
}

async function markAll(): Promise<void> {
  try {
    await store.markAllRead()
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  }
}
</script>

<template>
  <div ref="root" class="relative">
    <button
      class="relative grid h-9 w-9 place-items-center rounded-full text-slate-500 transition hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800"
      :aria-label="store.unread ? `Notificaciones: ${store.unread} sin leer` : 'Notificaciones'"
      :aria-expanded="open"
      aria-haspopup="true"
      @click="toggle"
    >
      <AppIcon name="bell" :size="20" />
      <span
        v-if="store.unread > 0"
        class="absolute -right-0.5 -top-0.5 grid h-[18px] min-w-[18px] place-items-center rounded-full bg-rose-600 px-1 text-[10px] font-bold leading-none text-white ring-2 ring-white dark:ring-slate-900"
        aria-hidden="true"
      >
        {{ badge }}
      </span>
    </button>

    <Transition
      enter-active-class="transition duration-100 ease-out"
      enter-from-class="scale-95 opacity-0"
      leave-active-class="transition duration-75 ease-in"
      leave-to-class="scale-95 opacity-0"
    >
      <div
        v-if="open"
        class="absolute right-0 z-30 mt-2 w-[22rem] max-w-[calc(100vw-2rem)] overflow-hidden rounded-lg border border-slate-200 bg-white shadow-lg dark:border-slate-800 dark:bg-slate-900"
        role="dialog"
        aria-label="Notificaciones"
      >
        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3 dark:border-slate-800">
          <p class="text-sm font-semibold text-slate-800 dark:text-slate-100">Notificaciones</p>
          <button
            v-if="store.unread > 0"
            class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400"
            @click="markAll"
          >
            Marcar todo como leído
          </button>
        </div>

        <div class="max-h-[26rem] overflow-y-auto">
          <div v-if="store.loadingRecent && store.recent.length === 0" class="grid place-items-center py-10">
            <Spinner :size="22" />
          </div>
          <div v-else-if="store.recent.length === 0" class="px-6 py-10 text-center">
            <span class="mx-auto mb-3 grid h-11 w-11 place-items-center rounded-full bg-slate-100 text-slate-400 dark:bg-slate-800">
              <AppIcon name="bell" :size="22" />
            </span>
            <p class="text-sm font-medium text-slate-700 dark:text-slate-200">Todo al día</p>
            <p class="mt-1 text-xs text-slate-500">Aquí verás aprobaciones, publicaciones y avisos de tu cuenta.</p>
          </div>
          <ul v-else class="divide-y divide-slate-100 dark:divide-slate-800">
            <li v-for="n in store.recent" :key="n.id">
              <button
                class="flex w-full gap-3 px-4 py-3 text-left transition hover:bg-slate-50 focus-visible:bg-slate-50 dark:hover:bg-slate-800/60 dark:focus-visible:bg-slate-800/60"
                @click="openNotification(n)"
              >
                <span class="mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-full" :class="levelStyles[n.level]">
                  <AppIcon :name="levelIcons[n.level]" :size="16" />
                </span>
                <span class="min-w-0 flex-1">
                  <span class="flex items-start justify-between gap-2">
                    <span
                      class="text-sm"
                      :class="n.read_at ? 'text-slate-600 dark:text-slate-300' : 'font-semibold text-slate-900 dark:text-white'"
                    >
                      {{ n.title }}
                    </span>
                    <span v-if="!n.read_at" class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-brand-600" aria-label="Sin leer" />
                  </span>
                  <span class="mt-0.5 line-clamp-2 block text-xs text-slate-500 dark:text-slate-400">{{ n.body }}</span>
                  <span class="mt-1 block text-[11px] text-slate-400">{{ relativeTime(n.created_at) }}</span>
                </span>
              </button>
            </li>
          </ul>
        </div>

        <RouterLink
          to="/app/notifications"
          class="block border-t border-slate-100 px-4 py-2.5 text-center text-sm font-medium text-brand-600 hover:bg-slate-50 dark:border-slate-800 dark:text-brand-400 dark:hover:bg-slate-800/60"
          @click="open = false"
        >
          Ver todas
        </RouterLink>
      </div>
    </Transition>
  </div>
</template>
