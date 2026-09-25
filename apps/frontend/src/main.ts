import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import { setImpersonationExpiredHandler, setUnauthorizedHandler } from '@/services/http'
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
