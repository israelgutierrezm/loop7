<script setup lang="ts">
import AppLogo from '@/components/AppLogo.vue'
import AppIcon from '@/components/AppIcon.vue'

const features = [
  { icon: 'brands', text: 'Gestiona múltiples marcas desde un solo lugar.' },
  { icon: 'calendar', text: 'Programa y publica en todas tus redes.' },
  { icon: 'shield', text: 'Seguridad y permisos de nivel empresarial.' },
]
</script>

<template>
  <div class="grid min-h-full lg:grid-cols-2">
    <!-- Panel de marca (solo desktop) -->
    <div class="relative hidden overflow-hidden bg-gradient-to-br from-brand-600 to-brand-900 p-12 text-white lg:flex lg:flex-col">
      <AppLogo class="[&_span:last-child]:text-white" />
      <div class="my-auto max-w-md">
        <h1 class="text-3xl font-bold leading-tight">
          La central de mando de tus redes sociales.
        </h1>
        <p class="mt-4 text-brand-100">
          Planifica, crea con IA, aprueba y publica. Todo con tu equipo, en una sola plataforma.
        </p>
        <ul class="mt-8 space-y-4">
          <li v-for="(f, i) in features" :key="i" class="flex items-center gap-3">
            <span class="grid h-9 w-9 place-items-center rounded-lg bg-white/15">
              <AppIcon :name="f.icon" :size="20" />
            </span>
            <span class="text-sm text-brand-50">{{ f.text }}</span>
          </li>
        </ul>
      </div>
      <p class="text-xs text-brand-200">© {{ new Date().getFullYear() }} Loop7</p>
    </div>

    <!-- Formulario -->
    <div class="flex items-center justify-center bg-slate-50 px-4 py-12 dark:bg-slate-950">
      <div class="w-full max-w-sm">
        <div class="mb-8 lg:hidden">
          <AppLogo />
        </div>
        <RouterView />
      </div>
    </div>
  </div>
</template>
