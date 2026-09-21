<script setup lang="ts">
import AppIcon from '@/components/AppIcon.vue'

withDefaults(
  defineProps<{ icon?: string; title: string; description?: string }>(),
  { icon: 'sparkles', description: '' },
)
</script>

<template>
  <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-slate-300 bg-white/60 px-6 py-14 text-center dark:border-slate-700 dark:bg-slate-900/40">
    <span class="mb-4 grid h-12 w-12 place-items-center rounded-full bg-brand-50 text-brand-600 dark:bg-brand-950/50">
      <AppIcon :name="icon" :size="24" />
    </span>
    <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ title }}</h3>
    <p v-if="description" class="mt-1 max-w-sm text-sm text-slate-500 dark:text-slate-400">
      {{ description }}
    </p>
    <div class="mt-5">
      <slot name="action" />
    </div>
  </div>
</template>
