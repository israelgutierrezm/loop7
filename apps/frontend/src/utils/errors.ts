import type { AxiosError } from 'axios'
import type { ApiError } from '@/types/api'

export function apiErrorMessage(error: unknown, fallback = 'Ocurrió un error inesperado.'): string {
  const err = error as AxiosError<ApiError>
  return err?.response?.data?.message ?? fallback
}

export function apiValidationErrors(error: unknown): Record<string, string[]> {
  const err = error as AxiosError<ApiError>
  return err?.response?.data?.errors ?? {}
}

export function apiErrorCode(error: unknown): string | null {
  const err = error as AxiosError<ApiError>
  return err?.response?.data?.code ?? null
}
