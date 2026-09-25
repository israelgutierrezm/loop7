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

// Sesión expirada (401): limpiar estado y volver al login.
setUnauthorizedHandler(() => {
  const auth = useAuthStore(pinia)
  auth.reset()
  if (router.currentRoute.value.name !== 'login') {
    router.push({ name: 'login' })
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
