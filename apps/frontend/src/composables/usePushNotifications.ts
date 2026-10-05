import { ref } from 'vue'
import http from '@/services/http'

/**
 * Avisos push (Web Push) en este navegador: registra el service worker
 * (public/sw.js), pide permiso, se suscribe con la clave VAPID de la plataforma
 * y da de alta o baja la suscripción en el backend.
 */
export type PushState = 'unsupported' | 'denied' | 'off' | 'on'

const SW_URL = '/sw.js'

export function pushSupported(): boolean {
  return typeof window !== 'undefined'
    && 'serviceWorker' in navigator
    && 'PushManager' in window
    && 'Notification' in window
}

function keyBytes(base64Url: string): Uint8Array<ArrayBuffer> {
  const padding = '='.repeat((4 - (base64Url.length % 4)) % 4)
  const raw = atob((base64Url + padding).replace(/-/g, '+').replace(/_/g, '/'))
  return Uint8Array.from(raw, (c) => c.charCodeAt(0))
}

/** ¿La suscripción es de la clave actual? Si SUPERADMIN la regeneró, ya no sirve. */
function sameKey(subscription: PushSubscription, publicKey: string): boolean {
  const current = subscription.options.applicationServerKey
  if (!current) return false
  const expected = keyBytes(publicKey)
  const actual = new Uint8Array(current)
  return actual.length === expected.length && actual.every((byte, i) => byte === expected[i])
}

async function currentSubscription(): Promise<PushSubscription | null> {
  const registration = await navigator.serviceWorker.getRegistration('/')
  return (await registration?.pushManager.getSubscription()) ?? null
}

/**
 * Al cerrar sesión, el navegador deja de recibir los avisos de esa cuenta: la
 * siguiente persona que lo use no debe verlos.
 */
export async function forgetPushSubscription(): Promise<void> {
  if (!pushSupported()) return
  try {
    const subscription = await currentSubscription()
    if (!subscription) return
    await http.delete('/me/push-subscriptions', { data: { endpoint: subscription.endpoint } }).catch(() => undefined)
    await subscription.unsubscribe()
  } catch {
    // Sin service worker o sin permiso: no hay nada que olvidar.
  }
}

export function usePushNotifications() {
  const state = ref<PushState>(pushSupported() ? 'off' : 'unsupported')

  /** Estado de este navegador; si la suscripción sigue vigente se re-sincroniza (idempotente). */
  async function refresh(publicKey: string | null): Promise<void> {
    if (!pushSupported()) return
    if (Notification.permission === 'denied') {
      state.value = 'denied'
      return
    }
    const subscription = await currentSubscription()
    if (subscription && publicKey && Notification.permission === 'granted' && sameKey(subscription, publicKey)) {
      await http.post('/me/push-subscriptions', subscription.toJSON())
      state.value = 'on'
    } else {
      state.value = 'off'
    }
  }

  /** @returns navegadores con push de la cuenta */
  async function enable(publicKey: string): Promise<number> {
    const permission = await Notification.requestPermission()
    if (permission !== 'granted') {
      state.value = permission === 'denied' ? 'denied' : 'off'
      throw new Error(permission === 'denied'
        ? 'Bloqueaste los avisos de este sitio. Permítelos en la configuración del navegador.'
        : 'Necesitamos tu permiso para mostrar avisos.')
    }

    await navigator.serviceWorker.register(SW_URL, { scope: '/' })
    const registration = await navigator.serviceWorker.ready

    let subscription = await registration.pushManager.getSubscription()
    if (subscription && !sameKey(subscription, publicKey)) {
      await subscription.unsubscribe()
      subscription = null
    }
    subscription ??= await registration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: keyBytes(publicKey),
    })

    const { data } = await http.post('/me/push-subscriptions', subscription.toJSON())
    state.value = 'on'
    return data.data.devices
  }

  /** @returns navegadores con push que le quedan a la cuenta, o null si no había suscripción */
  async function disable(): Promise<number | null> {
    const subscription = await currentSubscription()
    let devices: number | null = null
    if (subscription) {
      const { data } = await http.delete('/me/push-subscriptions', { data: { endpoint: subscription.endpoint } })
      devices = data.data.devices
      await subscription.unsubscribe()
    }
    state.value = 'off'
    return devices
  }

  return { state, refresh, enable, disable }
}
