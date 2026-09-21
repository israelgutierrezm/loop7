<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import AppIcon from '@/components/AppIcon.vue'

const route = useRoute()
const status = computed(() => (route.query.status as string) ?? '')
const ok = computed(() => status.value === 'success')
</script>

<template>
  <div class="text-center">
    <span
      class="mx-auto grid h-14 w-14 place-items-center rounded-full"
      :class="ok ? 'bg-emerald-100 text-emerald-600' : 'bg-rose-100 text-rose-600'"
    >
      <AppIcon :name="ok ? 'check' : 'alert'" :size="28" />
    </span>
    <h1 class="mt-5 text-2xl font-bold text-slate-900 dark:text-white">
      {{ ok ? 'Correo verificado' : 'Enlace no válido' }}
    </h1>
    <p class="mt-2 text-sm text-slate-500">
      {{ ok ? 'Tu dirección de correo fue verificada correctamente.' : 'El enlace es inválido o ya expiró.' }}
    </p>
    <RouterLink to="/app" class="btn-primary mt-6 inline-flex">Ir al panel</RouterLink>
  </div>
</template>
