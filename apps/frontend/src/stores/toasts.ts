import { defineStore } from 'pinia'
import { ref } from 'vue'

export type ToastType = 'success' | 'error' | 'info'

export interface Toast {
  id: number
  type: ToastType
  message: string
}

export const useToastStore = defineStore('toasts', () => {
  const toasts = ref<Toast[]>([])
  let counter = 0

  function push(message: string, type: ToastType = 'info', timeout = 4000): void {
    const id = ++counter
    toasts.value.push({ id, type, message })
    if (timeout > 0) {
      window.setTimeout(() => dismiss(id), timeout)
    }
  }

  function success(message: string): void {
    push(message, 'success')
  }

  function error(message: string): void {
    push(message, 'error', 6000)
  }

  function dismiss(id: number): void {
    toasts.value = toasts.value.filter((t) => t.id !== id)
  }

  return { toasts, push, success, error, dismiss }
})
