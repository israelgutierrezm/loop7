<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useEmailVerification } from '@/composables/useEmailVerification'
import AppIcon from '@/components/AppIcon.vue'

const route = useRoute()
const auth = useAuthStore()
const verification = useEmailVerification()
const status = computed(() => (route.query.status as string) ?? '')
const ok = computed(() => status.value === 'success')
const canResend = computed(() => !ok.value && auth.isAuthenticated && !auth.user?.email_verified)

onMounted(async () => {
  // Con sesión abierta, refleja el correo ya verificado (el aviso del panel desaparece).
  if (ok.value && auth.isAuthenticated) {
    try {
      await auth.fetchMe()
    } catch {
      /* se actualizará en la siguiente carga */
    }
  }
})
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
      {{ ok
        ? 'Tu dirección de correo fue verificada correctamente.'
        : canResend
          ? 'El enlace es inválido o ya caducó. Pide uno nuevo y ábrelo en cuanto llegue.'
          : 'El enlace es inválido o ya caducó. Inicia sesión y pide uno nuevo desde tu perfil.' }}
    </p>
    <div class="mt-6 flex flex-wrap justify-center gap-2">
      <button
        v-if="canResend"
        type="button"
        class="btn-secondary"
        :disabled="verification.sending.value || verification.sent.value"
        @click="verification.resend"
      >
        {{ verification.sent.value ? 'Enlace enviado' : verification.sending.value ? 'Enviando…' : 'Enviar un enlace nuevo' }}
      </button>
      <RouterLink :to="auth.isAuthenticated ? '/app' : '/login'" class="btn-primary">
        {{ auth.isAuthenticated ? 'Ir al panel' : 'Iniciar sesión' }}
      </RouterLink>
    </div>
  </div>
</template>
