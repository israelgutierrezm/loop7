import type { AxiosError } from 'axios'
import type { ApiError } from '@/types/api'

/**
 * Mensaje legible de un error de la API. En errores de validación devuelve el
 * primer motivo concreto (p. ej. "El plan no tiene precio en MXN…") en lugar
 * del genérico "Los datos proporcionados no son válidos".
 */
export function apiErrorMessage(error: unknown, fallback = 'Ocurrió un error inesperado.'): string {
  const err = error as AxiosError<ApiError>
  const data = err?.response?.data
  if (data?.code === 'validation_error' && data.errors) {
    const first = Object.values(data.errors).flat()[0]
    if (typeof first === 'string' && first !== '') return first
  }
  if (!err?.response && err?.message === 'Network Error') {
    return 'No hay conexión con el servidor. Revisa tu red e inténtalo de nuevo.'
  }
  return data?.message ?? fallback
}

export function apiValidationErrors(error: unknown): Record<string, string[]> {
  const err = error as AxiosError<ApiError>
  return err?.response?.data?.errors ?? {}
}

export function apiErrorCode(error: unknown): string | null {
  const err = error as AxiosError<ApiError>
  return err?.response?.data?.code ?? null
}
