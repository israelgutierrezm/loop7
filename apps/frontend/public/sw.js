/*
 * Service worker de Loop7: sólo muestra avisos push (Web Push). No intercepta
 * peticiones ni guarda nada en caché.
 *
 * La carga llega cifrada para este navegador y la envía el backend
 * (WebPushSender): { title, body, path, tag }. La ruta se abre siempre en el
 * origen del propio SPA.
 */

self.addEventListener('install', () => self.skipWaiting())
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()))

self.addEventListener('push', (event) => {
  let data = {}
  try {
    data = event.data ? event.data.json() : {}
  } catch {
    data = {}
  }

  const text = (value) => (typeof value === 'string' ? value : '')
  event.waitUntil(
    self.registration.showNotification(text(data.title) || 'Nuevo aviso', {
      body: text(data.body),
      icon: '/favicon.svg',
      tag: text(data.tag) || undefined,
      data: { path: text(data.path) || '/app' },
    }),
  )
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()

  const target = new URL(event.notification.data?.path || '/app', self.location.origin)
  // Sólo rutas del propio SPA, nunca otro origen.
  if (target.origin !== self.location.origin) return

  event.waitUntil(
    (async () => {
      const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
      const open = windows.find((client) => new URL(client.url).origin === self.location.origin)
      if (open) {
        await open.focus()
        try {
          await open.navigate(target.href)
          return
        } catch {
          // Pestaña no controlada por este service worker: se abre otra.
        }
      }
      await self.clients.openWindow(target.href)
    })(),
  )
})
