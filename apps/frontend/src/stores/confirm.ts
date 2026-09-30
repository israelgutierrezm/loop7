import { defineStore } from 'pinia'
import { ref } from 'vue'

export interface ConfirmOptions {
  title: string
  message?: string
  confirmText?: string
  cancelText?: string
  /** Acción destructiva: el botón de confirmar se muestra en rojo. */
  danger?: boolean
  /** Casilla opcional (p. ej. «Borrar también de las redes»); su valor llega con `askWithOption`. */
  option?: { label: string; hint?: string; checked?: boolean }
}

export interface ConfirmResult {
  ok: boolean
  /** Si se marcó la casilla (siempre false al cancelar). */
  option: boolean
}

interface PendingConfirm extends ConfirmOptions {
  optionChecked: boolean
  resolve: (result: ConfirmResult) => void
}

/**
 * Diálogo de confirmación accesible (sustituye a window.confirm).
 * Uso: `if (!(await confirm.ask({ title: '¿Eliminar?', danger: true }))) return`
 */
export const useConfirmStore = defineStore('confirm', () => {
  const current = ref<PendingConfirm | null>(null)

  function askWithOption(options: ConfirmOptions): Promise<ConfirmResult> {
    current.value?.resolve({ ok: false, option: false })
    return new Promise((resolve) => {
      current.value = { ...options, optionChecked: options.option?.checked ?? false, resolve }
    })
  }

  async function ask(options: ConfirmOptions): Promise<boolean> {
    return (await askWithOption(options)).ok
  }

  function answer(ok: boolean): void {
    const pending = current.value
    pending?.resolve({ ok, option: ok && pending.optionChecked })
    current.value = null
  }

  return { current, ask, askWithOption, answer }
})
