<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { usePublicConfigStore } from '@/stores/publicConfig'
import { dateLong } from '@/utils/format'
import AppIcon from '@/components/AppIcon.vue'

/**
 * Avisos globales del panel: aviso del sistema (SUPERADMIN → Configuración) y
 * estado de la suscripción de la organización (prueba por terminar, pago
 * pendiente, suscripción inactiva).
 */
const auth = useAuthStore()
const publicConfig = usePublicConfigStore()
const dismissedAnnouncement = ref<string | null>(null)

onMounted(() => {
  publicConfig.load()
  try {
    dismissedAnnouncement.value = sessionStorage.getItem('loop7.dismissedAnnouncement')
  } catch {
    /* almacenamiento no disponible */
  }
})

const announcement = computed(() =>
  publicConfig.announcement && publicConfig.announcement.message !== dismissedAnnouncement.value ? publicConfig.announcement : null,
)

function dismiss(): void {
  dismissedAnnouncement.value = publicConfig.announcement?.message ?? null
  try {
    if (dismissedAnnouncement.value) sessionStorage.setItem('loop7.dismissedAnnouncement', dismissedAnnouncement.value)
  } catch {
    /* almacenamiento no disponible */
  }
}

const subscriptionNotice = computed(() => {
  const s = auth.subscription
  if (!s) return null
  if (!s.grants_access) {
    return { tone: 'danger', text: `Tu suscripción está ${s.status_label.toLowerCase()}. Elige un plan para volver a publicar y usar todas las funciones.` }
  }
  if (s.status === 'grace') {
    return { tone: 'warning', text: 'No recibimos el pago de la renovación. Renueva tu plan para evitar la suspensión del servicio.' }
  }
  if (s.status === 'trialing' && s.trial_ends_at) {
    const days = Math.ceil((new Date(s.trial_ends_at).getTime() - Date.now()) / 86_400_000)
    if (days <= 5) {
      return { tone: 'info', text: `Tu prueba termina ${days <= 1 ? 'en menos de un día' : `en ${days} días`} (${dateLong(s.trial_ends_at)}). Elige un plan para no perder el acceso.` }
    }
  }
  return null
})

const tones: Record<string, string> = {
  info: 'bg-brand-600 text-white',
  warning: 'bg-amber-500 text-amber-950',
  danger: 'bg-rose-600 text-white',
}
</script>

<template>
  <div>
    <div v-if="announcement" class="flex items-center gap-3 px-4 py-2 text-sm" :class="tones[announcement.tone]" role="status">
      <AppIcon :name="announcement.tone === 'warning' ? 'alert' : 'info'" :size="16" class="shrink-0" />
      <p class="flex-1">{{ announcement.message }}</p>
      <button type="button" class="rounded p-1 opacity-80 hover:opacity-100" aria-label="Ocultar aviso" @click="dismiss">
        <AppIcon name="close" :size="14" />
      </button>
    </div>
    <div v-if="subscriptionNotice" class="flex flex-wrap items-center gap-3 px-4 py-2 text-sm" :class="tones[subscriptionNotice.tone]" role="status">
      <AppIcon name="billing" :size="16" class="shrink-0" />
      <p class="flex-1">{{ subscriptionNotice.text }}</p>
      <RouterLink
        v-if="auth.can('billing.view')"
        to="/app/billing"
        class="rounded-md bg-white/20 px-2.5 py-1 font-semibold hover:bg-white/30"
      >
        Ver planes
      </RouterLink>
    </div>
  </div>
</template>
