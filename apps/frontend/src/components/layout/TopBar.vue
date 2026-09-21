<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useUiStore } from '@/stores/ui'
import AppIcon from '@/components/AppIcon.vue'
import NotificationsBell from '@/components/layout/NotificationsBell.vue'
import UserMenu from '@/components/layout/UserMenu.vue'

const ui = useUiStore()
const route = useRoute()

const crumbs = computed<string[]>(() => {
  const list: string[] = []
  for (const m of route.matched) {
    const t = m.meta?.title as string | undefined
    if (t && !list.includes(t)) list.push(t)
  }
  return list
})
</script>

<template>
  <header
    class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200 bg-white/80 px-4 backdrop-blur dark:border-slate-800 dark:bg-slate-900/80 lg:px-6"
  >
    <button
      class="grid h-9 w-9 place-items-center rounded-lg text-slate-500 hover:bg-slate-100 lg:hidden dark:text-slate-400 dark:hover:bg-slate-800"
      aria-label="Abrir menú"
      @click="ui.openMobileDrawer()"
    >
      <AppIcon name="menu" :size="22" />
    </button>

    <button
      class="hidden h-9 w-9 place-items-center rounded-lg text-slate-500 hover:bg-slate-100 lg:grid dark:text-slate-400 dark:hover:bg-slate-800"
      aria-label="Contraer menú"
      @click="ui.toggleSidebar()"
    >
      <AppIcon name="menu" :size="20" />
    </button>

    <nav class="hidden min-w-0 flex-1 items-center gap-1.5 text-sm text-slate-400 sm:flex" aria-label="Ruta">
      <span>Inicio</span>
      <template v-for="(c, i) in crumbs" :key="i">
        <AppIcon name="chevron-right" :size="14" />
        <span :class="i === crumbs.length - 1 ? 'font-medium text-slate-700 dark:text-slate-200' : ''">
          {{ c }}
        </span>
      </template>
    </nav>

    <div class="ml-auto flex items-center gap-1">
      <NotificationsBell />
      <UserMenu />
    </div>
  </header>
</template>
