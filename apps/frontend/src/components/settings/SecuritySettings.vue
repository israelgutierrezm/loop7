<script setup lang="ts">
import { onMounted, ref } from 'vue'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage, apiValidationErrors } from '@/utils/errors'
import AppIcon from '@/components/AppIcon.vue'
import Spinner from '@/components/ui/Spinner.vue'

/**
 * Controles Enterprise (docs/03): exigir la verificación en dos pasos a todo el
 * equipo. Quien no la tenga sólo podrá ir a Mi perfil a activarla.
 */
interface Security {
  available: boolean
  require_two_factor: boolean
  members_without_two_factor: number
}

const auth = useAuthStore()
const toasts = useToastStore()
const security = ref<Security | null>(null)
const saving = ref(false)
const error = ref('')

async function load(): Promise<void> {
  try {
    const { data } = await http.get('/organization/security')
    security.value = data.data
  } catch (e) {
    toasts.error(apiErrorMessage(e, 'No se pudo cargar la seguridad de la organización.'))
  }
}

async function toggle(value: boolean): Promise<void> {
  if (!security.value) return
  saving.value = true
  error.value = ''
  try {
    const { data } = await http.put('/organization/security', { require_two_factor: value })
    security.value = data.data
    toasts.success(data.message)
  } catch (e) {
    error.value = apiValidationErrors(e).require_two_factor?.[0] ?? apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="card p-6" aria-labelledby="security-title">
    <h2 id="security-title" class="font-semibold text-slate-900 dark:text-white">Seguridad del equipo</h2>
    <p class="mt-1 text-sm text-slate-500">Controles para proteger el acceso de todos los miembros.</p>

    <div v-if="!security" class="skeleton mt-4 h-16 w-full" />

    <div v-else-if="!security.available" class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-slate-50 p-4 text-sm dark:bg-slate-800/60">
      <span class="flex items-center gap-2 text-slate-600 dark:text-slate-300">
        <AppIcon name="sparkles" :size="16" /> Exigir la verificación en dos pasos está disponible en el plan Enterprise.
      </span>
      <RouterLink v-if="auth.can('billing.view')" to="/app/billing" class="btn-secondary text-sm">Ver planes</RouterLink>
    </div>

    <div v-else class="mt-4 space-y-2">
      <label class="flex items-start gap-3 text-sm">
        <input
          type="checkbox"
          class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
          :checked="security.require_two_factor"
          :disabled="saving || auth.impersonating"
          @change="toggle(($event.target as HTMLInputElement).checked)"
        />
        <span>
          <span class="font-medium text-slate-800 dark:text-slate-100">Exigir la verificación en dos pasos</span>
          <span class="block text-xs text-slate-500">
            Quien no la tenga sólo podrá entrar a Mi perfil para activarla. Quien entra con el inicio de sesión único (SSO) no lo necesita: lo aplica su proveedor de identidad.
          </span>
        </span>
        <Spinner v-if="saving" :size="16" class="ml-auto" />
      </label>
      <p v-if="error" class="pl-7 text-xs text-rose-600">{{ error }}</p>
      <p class="pl-7 text-xs" :class="security.members_without_two_factor ? 'text-amber-700 dark:text-amber-400' : 'text-slate-500'">
        {{ security.members_without_two_factor === 0
          ? 'Todos los miembros la tienen activa.'
          : `${security.members_without_two_factor} ${security.members_without_two_factor === 1 ? 'miembro aún no la tiene' : 'miembros aún no la tienen'} (los ves en Equipo).` }}
      </p>
    </div>
  </section>
</template>
