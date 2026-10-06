<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { usePublicConfigStore } from '@/stores/publicConfig'
import { apiErrorCode, apiErrorMessage, apiValidationErrors } from '@/utils/errors'
import { ssoErrorMessage } from '@/utils/sso'
import Spinner from '@/components/ui/Spinner.vue'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()
const publicConfig = usePublicConfigStore()

const form = reactive({ email: '', password: '', code: '', remember: false })
const errors = ref<Record<string, string[]>>({})
const generalError = ref('')
const mfaRequired = ref(false)
const loading = ref(false)
/** Contraseña o inicio de sesión único con el proveedor de identidad de la empresa. */
const mode = ref<'password' | 'sso'>('password')

onMounted(() => {
  publicConfig.load()
  // Vuelta del proveedor de identidad con un error: se muestra y se limpia la URL.
  const ssoError = route.query.sso_error
  if (typeof ssoError === 'string') {
    mode.value = 'sso'
    generalError.value = ssoErrorMessage(ssoError)
    const query = { ...route.query }
    delete query.sso_error
    router.replace({ query })
  }
})

function useMode(next: 'password' | 'sso'): void {
  mode.value = next
  errors.value = {}
  generalError.value = ''
  mfaRequired.value = false
}

async function submit(): Promise<void> {
  loading.value = true
  errors.value = {}
  generalError.value = ''
  try {
    await auth.login({ ...form })
    // Sólo rutas internas: nunca otra web (//dominio o https://…).
    const requested = typeof route.query.redirect === 'string' ? route.query.redirect : ''
    const redirect = requested.startsWith('/') && !requested.startsWith('//') ? requested : '/app'
    router.push(redirect)
  } catch (e) {
    const code = apiErrorCode(e)
    if (code === 'mfa_required') {
      mfaRequired.value = true
      generalError.value = 'Introduce el código de tu app de autenticación.'
    } else if (code === 'mfa_invalid') {
      generalError.value = 'El código de verificación no es válido.'
    } else if (code === 'sso_required') {
      mode.value = 'sso'
      form.password = ''
      generalError.value = 'Tu organización exige entrar con su proveedor de identidad (SSO).'
    } else {
      errors.value = apiValidationErrors(e)
      generalError.value = Object.keys(errors.value).length ? '' : apiErrorMessage(e)
    }
  } finally {
    loading.value = false
  }
}

async function submitSso(): Promise<void> {
  loading.value = true
  errors.value = {}
  generalError.value = ''
  try {
    // Si todo va bien, el navegador sale hacia el proveedor de identidad.
    await auth.startSso(form.email)
  } catch (e) {
    errors.value = apiValidationErrors(e)
    generalError.value = Object.keys(errors.value).length ? '' : apiErrorMessage(e, 'No se pudo iniciar sesión con SSO.')
    loading.value = false
  }
}
</script>

<template>
  <div>
    <h1 class="text-2xl font-bold text-slate-900 dark:text-white">Inicia sesión</h1>
    <p class="mt-1 text-sm text-slate-500">
      {{ mode === 'sso' ? 'Entra con la cuenta de tu empresa.' : 'Bienvenido de nuevo a Loop7.' }}
    </p>

    <form v-if="mode === 'password'" class="mt-8 space-y-4" @submit.prevent="submit">
      <div>
        <label class="label" for="email">Correo electrónico</label>
        <input id="email" v-model="form.email" type="email" autocomplete="email" required class="input" />
        <p v-if="errors.email" class="mt-1 text-xs text-rose-600">{{ errors.email[0] }}</p>
      </div>

      <div>
        <div class="flex items-center justify-between">
          <label class="label" for="password">Contraseña</label>
          <RouterLink to="/recuperar-contrasena" class="text-xs font-medium text-brand-600 hover:underline">
            ¿La olvidaste?
          </RouterLink>
        </div>
        <input id="password" v-model="form.password" type="password" autocomplete="current-password" required class="input" />
        <p v-if="errors.password" class="mt-1 text-xs text-rose-600">{{ errors.password[0] }}</p>
      </div>

      <div v-if="mfaRequired">
        <label class="label" for="code">Código de verificación</label>
        <input id="code" v-model="form.code" inputmode="numeric" autocomplete="one-time-code" class="input tracking-widest" placeholder="123456" />
      </div>

      <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
        <input v-model="form.remember" type="checkbox" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500" />
        Mantener sesión iniciada
      </label>

      <p v-if="generalError" role="alert" class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:bg-rose-950/30">
        {{ generalError }}
      </p>

      <button type="submit" class="btn-primary w-full" :disabled="loading">
        <Spinner v-if="loading" :size="18" />
        {{ mfaRequired ? 'Verificar y entrar' : 'Entrar' }}
      </button>

      <div class="flex items-center gap-3 text-xs text-slate-400" aria-hidden="true">
        <span class="h-px flex-1 bg-slate-200 dark:bg-slate-700" /> o <span class="h-px flex-1 bg-slate-200 dark:bg-slate-700" />
      </div>
      <button type="button" class="btn-secondary w-full" @click="useMode('sso')">Continuar con SSO</button>
    </form>

    <form v-else class="mt-8 space-y-4" @submit.prevent="submitSso">
      <div>
        <label class="label" for="sso-email">Correo de trabajo</label>
        <input id="sso-email" v-model="form.email" type="email" autocomplete="email" required class="input" placeholder="tu@empresa.com" />
        <p class="mt-1 text-xs text-slate-500">Te llevaremos al proveedor de identidad de tu empresa (Microsoft Entra ID, Okta, Google…).</p>
        <p v-if="errors.email" class="mt-1 text-xs text-rose-600">{{ errors.email[0] }}</p>
      </div>

      <p v-if="generalError" role="alert" class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:bg-rose-950/30">
        {{ generalError }}
      </p>

      <button type="submit" class="btn-primary w-full" :disabled="loading">
        <Spinner v-if="loading" :size="18" />
        Continuar con SSO
      </button>
      <button type="button" class="btn-ghost w-full text-sm" @click="useMode('password')">Entrar con contraseña</button>
    </form>

    <p v-if="publicConfig.registrationOpen" class="mt-6 text-center text-sm text-slate-500">
      ¿No tienes cuenta?
      <RouterLink to="/registro" class="font-semibold text-brand-600 hover:underline">Regístrate</RouterLink>
    </p>
  </div>
</template>
