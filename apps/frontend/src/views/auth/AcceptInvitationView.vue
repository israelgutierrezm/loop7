<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage } from '@/utils/errors'
import Spinner from '@/components/ui/Spinner.vue'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toasts = useToastStore()

const state = ref<'loading' | 'error'>('loading')
const error = ref('')

onMounted(async () => {
  const token = route.query.token as string | undefined
  if (!token) {
    state.value = 'error'
    error.value = 'Falta el token de invitación.'
    return
  }
  try {
    await http.post('/invitations/accept', { token })
    await auth.fetchMe()
    toasts.success('Te uniste a la organización.')
    router.push('/app')
  } catch (e) {
    state.value = 'error'
    error.value = apiErrorMessage(e, 'La invitación no es válida o expiró.')
  }
})
</script>

<template>
  <div class="text-center">
    <template v-if="state === 'loading'">
      <Spinner :size="32" class="mx-auto text-brand-600" />
      <p class="mt-4 text-sm text-slate-500">Procesando tu invitación…</p>
    </template>
    <template v-else>
      <h1 class="text-2xl font-bold text-slate-900 dark:text-white">Invitación no válida</h1>
      <p class="mt-2 text-sm text-slate-500">{{ error }}</p>
      <RouterLink to="/app" class="btn-primary mt-6 inline-flex">Ir al panel</RouterLink>
    </template>
  </div>
</template>
