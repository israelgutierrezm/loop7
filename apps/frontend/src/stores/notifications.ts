import { defineStore } from 'pinia'
import { ref } from 'vue'
import http from '@/services/http'

export interface AppNotification {
  id: string
  kind: string
  category: string | null
  level: 'info' | 'success' | 'warning' | 'danger'
  title: string
  body: string
  path: string | null
  read_at: string | null
  created_at: string | null
}

const POLL_MS = 60_000

/**
 * Avisos in-app de la organización actual: contador de no leídos (sondeo
 * mientras la pestaña está visible) y últimos avisos para la campana.
 */
export const useNotificationsStore = defineStore('notifications', () => {
  const unread = ref(0)
  const recent = ref<AppNotification[]>([])
  const loadingRecent = ref(false)
  let timer: ReturnType<typeof setInterval> | null = null

  async function refreshCount(): Promise<void> {
    try {
      const { data } = await http.get('/notifications/unread-count')
      unread.value = data.data.unread ?? 0
    } catch {
      /* sin conexión o sesión caducada: se reintenta en el siguiente ciclo */
    }
  }

  async function loadRecent(): Promise<void> {
    loadingRecent.value = true
    try {
      const { data } = await http.get('/notifications', { params: { per_page: 8 } })
      recent.value = data.data
      unread.value = data.meta.unread ?? 0
    } finally {
      loadingRecent.value = false
    }
  }

  async function markRead(notification: AppNotification): Promise<void> {
    if (notification.read_at) return
    const { data } = await http.post(`/notifications/${notification.id}/read`)
    notification.read_at = data.data.read_at
    unread.value = data.meta.unread ?? Math.max(0, unread.value - 1)
    const inRecent = recent.value.find((n) => n.id === notification.id)
    if (inRecent) inRecent.read_at = data.data.read_at
  }

  async function markAllRead(): Promise<void> {
    await http.post('/notifications/read-all')
    const now = new Date().toISOString()
    recent.value.forEach((n) => (n.read_at ??= now))
    unread.value = 0
  }

  function onVisibility(): void {
    if (document.visibilityState === 'visible') void refreshCount()
  }

  /** Arranca el sondeo (idempotente); se reinicia al cambiar de organización. */
  function start(): void {
    stop()
    recent.value = []
    void refreshCount()
    timer = setInterval(() => {
      if (document.visibilityState === 'visible') void refreshCount()
    }, POLL_MS)
    document.addEventListener('visibilitychange', onVisibility)
  }

  function stop(): void {
    if (timer) clearInterval(timer)
    timer = null
    document.removeEventListener('visibilitychange', onVisibility)
  }

  return { unread, recent, loadingRecent, refreshCount, loadRecent, markRead, markAllRead, start, stop }
})
