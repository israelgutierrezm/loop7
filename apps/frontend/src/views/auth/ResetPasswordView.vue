<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { fetchCsrfCookie } from '@/services/http'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage, apiValidationErrors } from '@/utils/errors'
import Spinner from '@/components/ui/Spinner.vue'

const route = useRoute()
const router = useRouter()
const toasts = useToastStore()

const form = reactive({
  token: (route.query.token as string) ?? '',
  email: (route.query.email as string) ?? '',
  password: '',
  password_confirmation: '',
})
const errors = ref<Record<string, string[]>>({})
const generalError = ref('')
const loading = ref(false)

async function submit(): Promise<void> {
  loading.value = true
  errors.value = {}
  generalError.value = ''
  try {
    await fetchCsrfCookie()
    await http.post('/auth/reset-password', { ...form })
    toasts.success('Contraseña restablecida. Inicia sesión.')
    router.push('/login')
  } catch (e) {
    errors.value = apiValidationErrors(e)
    generalError.value = Object.keys(errors.value).length ? '' : apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div>
    <h1 class="text-2xl font-bold text-slate-900 dark:text-white">Nueva contraseña</h1>
    <p class="mt-1 text-sm text-slate-500">Elige una contraseña segura para tu cuenta.</p>

    <form class="mt-8 space-y-4" @submit.prevent="submit">
      <div>
        <label class="label" for="email">Correo</label>
        <input id="email" v-model="form.email" type="email" required class="input" />
      </div>
      <div>
        <label class="label" for="password">Nueva contraseña</label>
        <input id="password" v-model="form.password" type="password" autocomplete="new-password" required class="input" />
        <p v-if="errors.password" class="mt-1 text-xs text-rose-600">{{ errors.password[0] }}</p>
      </div>
      <div>
        <label class="label" for="password_confirmation">Confirmar contraseña</label>
        <input id="password_confirmation" v-model="form.password_confirmation" type="password" autocomplete="new-password" required class="input" />
      </div>
      <p v-if="generalError" class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:bg-rose-950/30">{{ generalError }}</p>
      <button type="submit" class="btn-primary w-full" :disabled="loading">
        <Spinner v-if="loading" :size="18" />
        Restablecer contraseña
      </button>
    </form>
  </div>
</template>
