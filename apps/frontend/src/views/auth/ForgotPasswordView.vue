<script setup lang="ts">
import { ref } from 'vue'
import http, { fetchCsrfCookie } from '@/services/http'
import { apiErrorMessage } from '@/utils/errors'
import Spinner from '@/components/ui/Spinner.vue'

const email = ref('')
const loading = ref(false)
const sent = ref(false)
const error = ref('')

async function submit(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    await fetchCsrfCookie()
    await http.post('/auth/forgot-password', { email: email.value })
    sent.value = true
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div>
    <h1 class="text-2xl font-bold text-slate-900 dark:text-white">Recuperar contraseña</h1>
    <p class="mt-1 text-sm text-slate-500">Te enviaremos un enlace para restablecerla.</p>

    <div v-if="sent" class="mt-8 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:bg-emerald-950/30">
      Si el correo está registrado, recibirás instrucciones en breve.
    </div>

    <form v-else class="mt-8 space-y-4" @submit.prevent="submit">
      <div>
        <label class="label" for="email">Correo electrónico</label>
        <input id="email" v-model="email" type="email" autocomplete="email" required class="input" />
      </div>
      <p v-if="error" class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:bg-rose-950/30">{{ error }}</p>
      <button type="submit" class="btn-primary w-full" :disabled="loading">
        <Spinner v-if="loading" :size="18" />
        Enviar enlace
      </button>
    </form>

    <p class="mt-6 text-center text-sm text-slate-500">
      <RouterLink to="/login" class="font-semibold text-brand-600 hover:underline">Volver a iniciar sesión</RouterLink>
    </p>
  </div>
</template>
