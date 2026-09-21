<script setup lang="ts">
import { reactive, ref } from 'vue'
import QRCode from 'qrcode'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage, apiValidationErrors } from '@/utils/errors'
import PageHeader from '@/components/ui/PageHeader.vue'
import Spinner from '@/components/ui/Spinner.vue'
import AppIcon from '@/components/AppIcon.vue'

const auth = useAuthStore()
const toasts = useToastStore()

// --- Perfil ---
const profileForm = reactive({
  name: auth.user?.name ?? '',
  locale: auth.user?.locale ?? 'es',
  timezone: auth.user?.timezone ?? 'UTC',
})
const savingProfile = ref(false)

async function saveProfile(): Promise<void> {
  savingProfile.value = true
  try {
    await http.patch('/me', { ...profileForm })
    await auth.fetchMe()
    toasts.success('Perfil actualizado.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    savingProfile.value = false
  }
}

// --- Contraseña ---
const pwForm = reactive({ current_password: '', password: '', password_confirmation: '' })
const pwErrors = ref<Record<string, string[]>>({})
const savingPw = ref(false)

async function savePassword(): Promise<void> {
  savingPw.value = true
  pwErrors.value = {}
  try {
    await http.put('/me/password', { ...pwForm })
    pwForm.current_password = ''
    pwForm.password = ''
    pwForm.password_confirmation = ''
    toasts.success('Contraseña actualizada.')
  } catch (e) {
    pwErrors.value = apiValidationErrors(e)
    if (!Object.keys(pwErrors.value).length) toasts.error(apiErrorMessage(e))
  } finally {
    savingPw.value = false
  }
}

// --- MFA ---
const enabled = ref(auth.user?.two_factor_enabled ?? false)
const setup = ref<{ secret: string; recovery: string[]; qr: string } | null>(null)
const confirmCode = ref('')
const mfaBusy = ref(false)
const disablePassword = ref('')

async function startMfa(): Promise<void> {
  mfaBusy.value = true
  try {
    const { data } = await http.post('/me/two-factor/enable')
    const qr = await QRCode.toDataURL(data.data.otpauth_uri, { width: 200, margin: 1 })
    setup.value = { secret: data.data.secret, recovery: data.data.recovery_codes, qr }
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    mfaBusy.value = false
  }
}

async function confirmMfa(): Promise<void> {
  mfaBusy.value = true
  try {
    await http.post('/me/two-factor/confirm', { code: confirmCode.value })
    enabled.value = true
    setup.value = null
    confirmCode.value = ''
    await auth.fetchMe()
    toasts.success('Doble factor activado.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    mfaBusy.value = false
  }
}

async function disableMfa(): Promise<void> {
  mfaBusy.value = true
  try {
    await http.delete('/me/two-factor', { data: { password: disablePassword.value } })
    enabled.value = false
    disablePassword.value = ''
    await auth.fetchMe()
    toasts.success('Doble factor desactivado.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    mfaBusy.value = false
  }
}
</script>

<template>
  <div class="max-w-2xl space-y-6">
    <PageHeader title="Mi perfil" description="Gestiona tu información personal y seguridad." />

    <!-- Perfil -->
    <form class="card p-6" @submit.prevent="saveProfile">
      <h2 class="mb-4 font-semibold text-slate-900 dark:text-white">Información personal</h2>
      <div class="space-y-4">
        <div>
          <label class="label" for="p-name">Nombre</label>
          <input id="p-name" v-model="profileForm.name" type="text" class="input" />
        </div>
        <div>
          <label class="label" for="p-email">Correo</label>
          <input id="p-email" :value="auth.user?.email" type="email" disabled class="input" />
        </div>
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="label" for="p-locale">Idioma</label>
            <select id="p-locale" v-model="profileForm.locale" class="input">
              <option value="es">Español</option>
              <option value="en">English</option>
            </select>
          </div>
          <div>
            <label class="label" for="p-tz">Zona horaria</label>
            <input id="p-tz" v-model="profileForm.timezone" type="text" class="input" />
          </div>
        </div>
      </div>
      <div class="mt-6 flex justify-end">
        <button type="submit" class="btn-primary" :disabled="savingProfile">
          <Spinner v-if="savingProfile" :size="18" /> Guardar
        </button>
      </div>
    </form>

    <!-- Contraseña -->
    <form class="card p-6" @submit.prevent="savePassword">
      <h2 class="mb-4 font-semibold text-slate-900 dark:text-white">Cambiar contraseña</h2>
      <div class="space-y-4">
        <div>
          <label class="label" for="cp">Contraseña actual</label>
          <input id="cp" v-model="pwForm.current_password" type="password" autocomplete="current-password" class="input" />
          <p v-if="pwErrors.current_password" class="mt-1 text-xs text-rose-600">{{ pwErrors.current_password[0] }}</p>
        </div>
        <div>
          <label class="label" for="np">Nueva contraseña</label>
          <input id="np" v-model="pwForm.password" type="password" autocomplete="new-password" class="input" />
          <p v-if="pwErrors.password" class="mt-1 text-xs text-rose-600">{{ pwErrors.password[0] }}</p>
        </div>
        <div>
          <label class="label" for="ncp">Confirmar nueva contraseña</label>
          <input id="ncp" v-model="pwForm.password_confirmation" type="password" autocomplete="new-password" class="input" />
        </div>
      </div>
      <div class="mt-6 flex justify-end">
        <button type="submit" class="btn-primary" :disabled="savingPw">
          <Spinner v-if="savingPw" :size="18" /> Actualizar contraseña
        </button>
      </div>
    </form>

    <!-- MFA -->
    <div class="card p-6">
      <div class="flex items-center justify-between">
        <div>
          <h2 class="font-semibold text-slate-900 dark:text-white">Verificación en dos pasos</h2>
          <p class="mt-1 text-sm text-slate-500">Añade una capa extra de seguridad con una app TOTP.</p>
        </div>
        <span
          class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold"
          :class="enabled ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500 dark:bg-slate-800'"
        >
          <AppIcon :name="enabled ? 'check' : 'shield'" :size="14" />
          {{ enabled ? 'Activado' : 'Desactivado' }}
        </span>
      </div>

      <!-- Activado: permitir desactivar -->
      <form v-if="enabled" class="mt-5 flex items-end gap-3" @submit.prevent="disableMfa">
        <div class="flex-1">
          <label class="label" for="dp">Confirma tu contraseña para desactivar</label>
          <input id="dp" v-model="disablePassword" type="password" class="input" />
        </div>
        <button type="submit" class="btn-secondary text-rose-600" :disabled="mfaBusy">Desactivar</button>
      </form>

      <!-- Setup en curso -->
      <div v-else-if="setup" class="mt-5">
        <div class="flex flex-col items-center gap-4 sm:flex-row sm:items-start">
          <img :src="setup.qr" alt="Código QR" class="rounded-lg border border-slate-200 dark:border-slate-700" />
          <div class="flex-1">
            <p class="text-sm text-slate-600 dark:text-slate-300">
              Escanea el código con tu app de autenticación, o introduce esta clave manualmente:
            </p>
            <code class="mt-2 block break-all rounded-lg bg-slate-100 px-3 py-2 text-sm dark:bg-slate-800">
              {{ setup.secret }}
            </code>
            <p class="mt-3 text-xs font-semibold uppercase text-slate-400">Códigos de recuperación</p>
            <div class="mt-1 grid grid-cols-2 gap-1 text-xs">
              <code v-for="code in setup.recovery" :key="code" class="rounded bg-slate-100 px-2 py-1 dark:bg-slate-800">
                {{ code }}
              </code>
            </div>
          </div>
        </div>
        <form class="mt-4 flex items-end gap-3" @submit.prevent="confirmMfa">
          <div class="flex-1">
            <label class="label" for="cc">Código de verificación</label>
            <input id="cc" v-model="confirmCode" inputmode="numeric" class="input tracking-widest" placeholder="123456" />
          </div>
          <button type="submit" class="btn-primary" :disabled="mfaBusy">
            <Spinner v-if="mfaBusy" :size="18" /> Confirmar
          </button>
        </form>
      </div>

      <!-- Desactivado: iniciar -->
      <div v-else class="mt-5">
        <button class="btn-primary" :disabled="mfaBusy" @click="startMfa">
          <Spinner v-if="mfaBusy" :size="18" /> Activar doble factor
        </button>
      </div>
    </div>
  </div>
</template>
