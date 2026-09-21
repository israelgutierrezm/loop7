<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage, apiValidationErrors } from '@/utils/errors'
import Spinner from '@/components/ui/Spinner.vue'

const auth = useAuthStore()
const toasts = useToastStore()
const router = useRouter()

const form = reactive({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
  organization_name: '',
  accept_terms: false,
})
const errors = ref<Record<string, string[]>>({})
const generalError = ref('')
const loading = ref(false)

async function submit(): Promise<void> {
  loading.value = true
  errors.value = {}
  generalError.value = ''
  try {
    await auth.register({ ...form })
    toasts.success('¡Cuenta creada! Revisa tu correo para verificarla.')
    router.push('/app')
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
    <h1 class="text-2xl font-bold text-slate-900 dark:text-white">Crea tu cuenta</h1>
    <p class="mt-1 text-sm text-slate-500">Empieza a gestionar tus redes en minutos.</p>

    <form class="mt-8 space-y-4" @submit.prevent="submit">
      <div>
        <label class="label" for="name">Tu nombre</label>
        <input id="name" v-model="form.name" type="text" autocomplete="name" required class="input" />
        <p v-if="errors.name" class="mt-1 text-xs text-rose-600">{{ errors.name[0] }}</p>
      </div>

      <div>
        <label class="label" for="org">Nombre de tu organización <span class="text-slate-400">(opcional)</span></label>
        <input id="org" v-model="form.organization_name" type="text" class="input" placeholder="Mi empresa" />
      </div>

      <div>
        <label class="label" for="email">Correo electrónico</label>
        <input id="email" v-model="form.email" type="email" autocomplete="email" required class="input" />
        <p v-if="errors.email" class="mt-1 text-xs text-rose-600">{{ errors.email[0] }}</p>
      </div>

      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
          <label class="label" for="password">Contraseña</label>
          <input id="password" v-model="form.password" type="password" autocomplete="new-password" required class="input" />
          <p v-if="errors.password" class="mt-1 text-xs text-rose-600">{{ errors.password[0] }}</p>
        </div>
        <div>
          <label class="label" for="password_confirmation">Confirmar</label>
          <input id="password_confirmation" v-model="form.password_confirmation" type="password" autocomplete="new-password" required class="input" />
        </div>
      </div>

      <label class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
        <input v-model="form.accept_terms" type="checkbox" class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500" />
        <span>Acepto los términos y condiciones y la política de privacidad.</span>
      </label>
      <p v-if="errors.accept_terms" class="text-xs text-rose-600">{{ errors.accept_terms[0] }}</p>

      <p v-if="generalError" class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:bg-rose-950/30">
        {{ generalError }}
      </p>

      <button type="submit" class="btn-primary w-full" :disabled="loading">
        <Spinner v-if="loading" :size="18" />
        Crear cuenta
      </button>
    </form>

    <p class="mt-6 text-center text-sm text-slate-500">
      ¿Ya tienes cuenta?
      <RouterLink to="/login" class="font-semibold text-brand-600 hover:underline">Inicia sesión</RouterLink>
    </p>
  </div>
</template>
