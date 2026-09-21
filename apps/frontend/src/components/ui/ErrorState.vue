<script setup lang="ts">
import AppIcon from '@/components/AppIcon.vue'

withDefaults(
  defineProps<{ title?: string; description?: string }>(),
  { title: 'Algo salió mal', description: 'No pudimos cargar la información. Inténtalo de nuevo.' },
)

defineEmits<{ retry: [] }>()
</script>

<template>
  <div class="flex flex-col items-center justify-center rounded-xl border border-rose-200 bg-rose-50/60 px-6 py-12 text-center dark:border-rose-900/40 dark:bg-rose-950/20">
    <span class="mb-4 grid h-12 w-12 place-items-center rounded-full bg-rose-100 text-rose-600 dark:bg-rose-900/40">
      <AppIcon name="alert" :size="24" />
    </span>
    <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ title }}</h3>
    <p class="mt-1 max-w-sm text-sm text-slate-500 dark:text-slate-400">{{ description }}</p>
    <button class="btn-secondary mt-5" @click="$emit('retry')">Reintentar</button>
  </div>
</template>
