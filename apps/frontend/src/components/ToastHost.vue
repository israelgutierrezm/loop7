<script setup lang="ts">
import { useToastStore } from '@/stores/toasts'
import AppIcon from '@/components/AppIcon.vue'

const toasts = useToastStore()

const styles: Record<string, string> = {
  success: 'border-emerald-200 bg-emerald-50 text-emerald-800',
  error: 'border-rose-200 bg-rose-50 text-rose-800',
  info: 'border-slate-200 bg-white text-slate-800',
}

const icons: Record<string, string> = {
  success: 'check',
  error: 'alert',
  info: 'info',
}
</script>

<template>
  <div class="pointer-events-none fixed inset-x-0 top-4 z-[100] flex flex-col items-center gap-2 px-4">
    <TransitionGroup
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="-translate-y-2 opacity-0"
      leave-active-class="transition duration-150 ease-in"
      leave-to-class="opacity-0"
    >
      <div
        v-for="toast in toasts.toasts"
        :key="toast.id"
        class="pointer-events-auto flex w-full max-w-md items-start gap-3 rounded-lg border px-4 py-3 shadow-lg"
        :class="styles[toast.type]"
        role="status"
      >
        <AppIcon :name="icons[toast.type]" :size="20" class="mt-0.5 shrink-0" />
        <p class="flex-1 text-sm font-medium">{{ toast.message }}</p>
        <button
          class="shrink-0 opacity-60 transition hover:opacity-100"
          aria-label="Cerrar"
          @click="toasts.dismiss(toast.id)"
        >
          <AppIcon name="close" :size="16" />
        </button>
      </div>
    </TransitionGroup>
  </div>
</template>
