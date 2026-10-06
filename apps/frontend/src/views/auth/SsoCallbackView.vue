<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import type { AxiosError } from 'axios'
import { useAuthStore } from '@/stores/auth'
import { ssoErrorMessage } from '@/utils/sso'
import Spinner from '@/components/ui/Spinner.vue'

const auth = useAuthStore()
const router = useRouter()
const error = ref('')

onMounted(async () => {
  // El código llega en el fragmento (#code=…): se quita de la URL en cuanto se lee.
  const code = new URLSearchParams(window.location.hash.slice(1)).get('code') ?? ''
  window.history.replaceState(window.history.state, '', window.location.pathname)

  if (!/^[A-Za-z0-9]{64}$/.test(code)) {
    error.value = ssoErrorMessage('expired')
    return
  }

  try {
    await auth.completeSso(code)
    router.replace('/app')
  } catch (e) {
    const reason = (e as AxiosError<{ errors?: { reason?: string } }>).response?.data?.errors?.reason
    error.value = ssoErrorMessage(reason ?? 'expired')
  }
})
</script>

<template>
  <div>
    <div v-if="!error" class="flex flex-col items-center gap-3 py-10 text-center" role="status">
      <Spinner :size="28" />
      <p class="text-sm text-slate-500">Iniciando sesión con tu empresa…</p>
    </div>
    <div v-else>
      <h1 class="text-2xl font-bold text-slate-900 dark:text-white">No se pudo iniciar sesión</h1>
      <p role="alert" class="mt-4 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:bg-rose-950/30">{{ error }}</p>
      <RouterLink to="/login" class="btn-primary mt-6 w-full">Volver a iniciar sesión</RouterLink>
    </div>
  </div>
</template>
