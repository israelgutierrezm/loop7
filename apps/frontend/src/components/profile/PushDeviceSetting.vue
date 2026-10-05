<script setup lang="ts">
import { onMounted, ref } from 'vue'
import http from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage } from '@/utils/errors'
import { usePushNotifications } from '@/composables/usePushNotifications'
import Spinner from '@/components/ui/Spinner.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import AppIcon from '@/components/AppIcon.vue'

/** Activar o desactivar los avisos push en el navegador actual. */
const props = defineProps<{ publicKey: string }>()
const emit = defineEmits<{ devices: [count: number] }>()

const auth = useAuthStore()
const toasts = useToastStore()
const push = usePushNotifications()
const busy = ref(false)
const testing = ref(false)

onMounted(async () => {
  // Impersonando, el navegador es del administrador: no se registra en esta cuenta.
  if (auth.impersonating) return
  try {
    await push.refresh(props.publicKey)
  } catch {
    // Si la sincronización falla, el estado queda en «desactivado» y se puede reintentar.
  }
})

async function enable(): Promise<void> {
  busy.value = true
  try {
    emit('devices', await push.enable(props.publicKey))
    toasts.success('Avisos push activados en este navegador.')
  } catch (e) {
    toasts.error(enableError(e))
  } finally {
    busy.value = false
  }
}

function enableError(e: unknown): string {
  // Errores del propio navegador al suscribirse (servicio push caído, modo privado…).
  if (e instanceof DOMException) return 'El navegador no pudo activar los avisos push. Inténtalo de nuevo más tarde.'
  if (e instanceof Error && !('isAxiosError' in e)) return e.message
  return apiErrorMessage(e)
}

async function disable(): Promise<void> {
  busy.value = true
  try {
    const devices = await push.disable()
    if (devices !== null) emit('devices', devices)
    toasts.success('Avisos push desactivados en este navegador.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function sendTest(): Promise<void> {
  testing.value = true
  try {
    const { data } = await http.post('/me/push-subscriptions/test')
    toasts.success(data.message ?? 'Aviso de prueba enviado.')
  } catch (e) {
    toasts.error(apiErrorMessage(e))
  } finally {
    testing.value = false
  }
}
</script>

<template>
  <div class="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-slate-200 p-4 dark:border-slate-700">
    <div class="flex min-w-0 items-start gap-3">
      <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-950/50">
        <AppIcon name="bell" :size="18" />
      </span>
      <div class="min-w-0">
        <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-slate-800 dark:text-slate-100">
          Avisos push en este navegador
          <StatusBadge v-if="push.state.value === 'on'" tone="success" dot>Activados</StatusBadge>
        </p>
        <p class="text-xs text-slate-500">
          <template v-if="push.state.value === 'unsupported'">
            Este navegador no admite avisos push. En iPhone, añade la app a la pantalla de inicio.
          </template>
          <template v-else-if="push.state.value === 'denied'">
            Bloqueaste los avisos de este sitio: permítelos en la configuración del navegador.
          </template>
          <template v-else>
            Llegan aunque la app esté cerrada. Actívalos en cada navegador o dispositivo que uses.
          </template>
        </p>
      </div>
    </div>
    <div v-if="push.state.value === 'on' || push.state.value === 'off'" class="flex flex-wrap gap-2">
      <button v-if="push.state.value === 'on'" type="button" class="btn-ghost text-sm" :disabled="testing" @click="sendTest">
        <Spinner v-if="testing" :size="16" /> Enviar prueba
      </button>
      <button
        type="button"
        class="text-sm"
        :class="push.state.value === 'on' ? 'btn-secondary' : 'btn-primary'"
        :disabled="busy"
        @click="push.state.value === 'on' ? disable() : enable()"
      >
        <Spinner v-if="busy" :size="16" />
        {{ push.state.value === 'on' ? 'Desactivar' : 'Activar en este navegador' }}
      </button>
    </div>
  </div>
</template>
