<script setup lang="ts">
import AppIcon from '@/components/AppIcon.vue'

defineProps<{ label: string; value: string | number; icon: string; hint?: string }>()
</script>

<template>
  <div class="card flex items-center gap-4 p-5">
    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-950/50">
      <AppIcon :name="icon" :size="22" />
    </span>
    <div class="min-w-0">
      <p class="truncate text-sm text-slate-500">{{ label }}</p>
      <p class="text-xl font-bold text-slate-900 dark:text-white">{{ value }}</p>
      <p v-if="hint" class="truncate text-xs text-slate-400">{{ hint }}</p>
    </div>
  </div>
</template>
