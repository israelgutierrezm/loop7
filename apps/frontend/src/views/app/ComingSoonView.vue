<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import EmptyState from '@/components/ui/EmptyState.vue'

const route = useRoute()
const title = computed(() => (route.meta.title as string) ?? 'Próximamente')
</script>

<template>
  <div>
    <h1 class="mb-6 text-2xl font-bold tracking-tight text-slate-900 dark:text-white">{{ title }}</h1>
    <EmptyState
      icon="sparkles"
      title="Este módulo llegará pronto"
      description="Estamos construyendo esta sección. Forma parte del roadmap del producto y estará disponible en una próxima fase."
    />
  </div>
</template>
