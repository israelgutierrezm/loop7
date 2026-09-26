import { onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

/**
 * Ejecuta una acción de la vista cuando la URL trae `?{param}=1` (p. ej. el
 * buscador de comandos abre «Nueva marca» con /app/brands?crear=1) y limpia el
 * parámetro para que no se repita al recargar.
 */
export function useQueryAction(param: string, action: () => void): void {
  const route = useRoute()
  const router = useRouter()

  function check(): void {
    if (route.query[param] !== '1') return
    action()
    const query = { ...route.query }
    delete query[param]
    void router.replace({ query })
  }

  onMounted(check)
  watch(() => route.query[param], check)
}
