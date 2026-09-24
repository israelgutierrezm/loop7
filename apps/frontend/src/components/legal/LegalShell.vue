<script setup lang="ts">
import { useCompany } from '@/composables/useCompany'
import AppLogo from '@/components/AppLogo.vue'

defineProps<{ title: string; updated?: string }>()

const company = useCompany()
</script>

<template>
  <div class="min-h-full bg-slate-50 text-slate-800 dark:bg-slate-950 dark:text-slate-200">
    <header class="border-b border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
      <div class="mx-auto flex h-16 max-w-3xl items-center justify-between px-4">
        <RouterLink to="/" aria-label="Inicio"><AppLogo /></RouterLink>
        <RouterLink to="/login" class="text-sm text-brand-600 hover:underline">Ir a la app</RouterLink>
      </div>
    </header>

    <main class="mx-auto max-w-3xl px-4 py-10">
      <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">{{ title }}</h1>
      <p v-if="updated" class="mt-1 text-sm text-slate-400">Última actualización: {{ updated }}</p>
      <p
        v-if="company.loaded.value && !company.complete.value"
        class="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-950/40 dark:text-amber-200"
      >
        Faltan datos de la empresa (razón social, correo de contacto o país). Complétalos en SUPERADMIN → Configuración
        y revisa este texto con asesoría legal antes de publicarlo.
      </p>
      <article class="legal mt-6 space-y-4 text-sm leading-relaxed text-slate-600 dark:text-slate-300">
        <slot />
      </article>
    </main>

    <footer class="border-t border-slate-200 py-6 dark:border-slate-800">
      <div class="mx-auto flex max-w-3xl flex-wrap justify-center gap-4 px-4 text-xs text-slate-400">
        <RouterLink to="/privacidad" class="hover:text-brand-600">Privacidad</RouterLink>
        <RouterLink to="/terminos" class="hover:text-brand-600">Términos</RouterLink>
        <RouterLink to="/eliminar-datos" class="hover:text-brand-600">Borrado de datos</RouterLink>
        <span>© {{ new Date().getFullYear() }} {{ company.name.value }}</span>
      </div>
    </footer>
  </div>
</template>

<style scoped>
.legal :deep(h2) {
  margin-top: 1.75rem;
  margin-bottom: 0.25rem;
  font-size: 1.05rem;
  font-weight: 600;
  color: rgb(15 23 42);
}
:global(.dark) .legal :deep(h2) {
  color: rgb(241 245 249);
}
.legal :deep(ul) {
  margin-left: 1.1rem;
  list-style: disc;
}
.legal :deep(a) {
  color: rgb(79 70 229);
  text-decoration: underline;
}
</style>
