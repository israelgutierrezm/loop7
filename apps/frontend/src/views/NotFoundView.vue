<script setup lang="ts">
import AppLogo from '@/components/AppLogo.vue'
</script>

<template>
  <div class="flex min-h-screen flex-col items-center justify-center gap-6 bg-slate-50 px-4 text-center dark:bg-slate-950">
    <AppLogo />
    <div>
      <p class="text-6xl font-bold text-brand-600">404</p>
      <h1 class="mt-2 text-xl font-semibold text-slate-900 dark:text-white">Página no encontrada</h1>
      <p class="mt-1 text-sm text-slate-500">La página que buscas no existe o fue movida.</p>
    </div>
    <RouterLink to="/app" class="btn-primary">Volver al inicio</RouterLink>
  </div>
</template>
