import { defineStore } from 'pinia'
import { ref } from 'vue'

export interface ConfirmOptions {
  title: string
  message?: string
  confirmText?: string
  cancelText?: string
  /** Acción destructiva: el botón de confirmar se muestra en rojo. */
  danger?: boolean
}

interface PendingConfirm extends ConfirmOptions {
  resolve: (ok: boolean) => void
}

/**
 * Diálogo de confirmación accesible (sustituye a window.confirm).
 * Uso: `if (!(await confirm.ask({ title: '¿Eliminar?', danger: true }))) return`
 */
export const useConfirmStore = defineStore('confirm', () => {
  const current = ref<PendingConfirm | null>(null)

  function ask(options: ConfirmOptions): Promise<boolean> {
    current.value?.resolve(false)
    return new Promise((resolve) => {
      current.value = { ...options, resolve }
    })
  }

  function answer(ok: boolean): void {
    current.value?.resolve(ok)
    current.value = null
  }

  return { current, ask, answer }
})
