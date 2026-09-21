<script setup lang="ts">
import { ref } from 'vue'
import { onClickOutside } from '@vueuse/core'
import AppIcon from '@/components/AppIcon.vue'
import EmptyState from '@/components/ui/EmptyState.vue'

const open = ref(false)
const root = ref<HTMLElement | null>(null)
onClickOutside(root, () => (open.value = false))
</script>

<template>
  <div ref="root" class="relative">
    <button
      class="relative grid h-9 w-9 place-items-center rounded-full text-slate-500 transition hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800"
      aria-label="Notificaciones"
      @click="open = !open"
    >
      <AppIcon name="bell" :size="20" />
    </button>

    <Transition
      enter-active-class="transition duration-100 ease-out"
      enter-from-class="scale-95 opacity-0"
      leave-active-class="transition duration-75 ease-in"
      leave-to-class="scale-95 opacity-0"
    >
      <div
        v-if="open"
        class="absolute right-0 z-30 mt-2 w-80 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-lg dark:border-slate-800 dark:bg-slate-900"
      >
        <div class="border-b border-slate-100 px-4 py-3 dark:border-slate-800">
          <p class="text-sm font-semibold text-slate-800 dark:text-slate-100">Notificaciones</p>
        </div>
        <div class="p-4">
          <EmptyState
            icon="bell"
            title="Todo al día"
            description="No tienes notificaciones nuevas."
          />
        </div>
      </div>
    </Transition>
  </div>
</template>
