import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import { setUnauthorizedHandler } from '@/services/http'
import { useAuthStore } from '@/stores/auth'
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

app.mount('#app')
