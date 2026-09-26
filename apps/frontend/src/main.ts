import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import {
  setAccountBlockedHandler,
  setImpersonationExpiredHandler,
  setOrganizationLostHandler,
  setOrganizationSuspendedHandler,
  setUnauthorizedHandler,
} from '@/services/http'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import './assets/main.css'

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)
app.use(router)

// Sesión expirada (401) a mitad de uso: limpiar estado y volver al login recordando
// la página. Un visitante anónimo (el 401 de /me al arrancar) se queda donde está: las
// páginas públicas (legales, registro, restablecer contraseña…) no redirigen, y las
// protegidas ya las lleva al login la guarda del router.
setUnauthorizedHandler(() => {
  const auth = useAuthStore(pinia)
  const hadSession = auth.isAuthenticated
  auth.reset()
  const current = router.currentRoute.value
  if (hadSession && current.meta.requiresAuth) {
    router.push({ name: 'login', query: { redirect: current.fullPath } })
  }
})

// Soporte bloqueó la cuenta con la sesión abierta: el backend ya la cerró.
setAccountBlockedHandler(() => {
  const auth = useAuthStore(pinia)
  if (!auth.isAuthenticated) return
  auth.reset()
  useToastStore(pinia).error('Tu cuenta está bloqueada. Escribe a soporte para más información.')
  router.push({ name: 'login' })
})

// La organización actual se suspendió: el panel pasa a mostrar el aviso (AdminLayout).
setOrganizationSuspendedHandler(() => useAuthStore(pinia).markCurrentSuspended())

// Ya no es miembro activo de la organización actual: se recarga su lista (otra
// organización o «Crea tu organización»). Una sola vez aunque fallen varias peticiones.
let reloadingOrganizations = false
setOrganizationLostHandler(async () => {
  const auth = useAuthStore(pinia)
  if (reloadingOrganizations || !auth.isAuthenticated) return
  reloadingOrganizations = true
  try {
    const lost = auth.currentOrganization?.name
    await auth.fetchMe()
    if (lost) useToastStore(pinia).error(`Ya no tienes acceso a «${lost}».`)
    await router.push('/app')
  } finally {
    reloadingOrganizations = false
  }
})

// La impersonación caducó (60 min): la sesión ya es otra vez la del administrador.
let returningFromImpersonation = false
setImpersonationExpiredHandler(async () => {
  if (returningFromImpersonation) return
  returningFromImpersonation = true
  try {
    await useAuthStore(pinia).fetchMe()
    useToastStore(pinia).error('La impersonación caducó: vuelves a tu cuenta de administrador.')
    await router.push('/platform')
  } finally {
    returningFromImpersonation = false
  }
})

app.mount('#app')
