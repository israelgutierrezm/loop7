import { ref } from 'vue'
import http from '@/services/http'
import { useToastStore } from '@/stores/toasts'
import { apiErrorMessage } from '@/utils/errors'

/** Reenvía el enlace de verificación del correo del usuario autenticado. */
export function useEmailVerification() {
  const toasts = useToastStore()
  const sending = ref(false)
  const sent = ref(false)

  async function resend(): Promise<void> {
    if (sending.value) return
    sending.value = true
    try {
      const { data } = await http.post('/email/verification-notification')
      sent.value = true
      toasts.success(data.message ?? 'Te enviamos un enlace nuevo: revisa tu correo.')
    } catch (e) {
      toasts.error(apiErrorMessage(e))
    } finally {
      sending.value = false
    }
  }

  return { sending, sent, resend }
}
