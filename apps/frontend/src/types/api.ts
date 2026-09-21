export interface ApiSuccess<T> {
  data: T
  meta: Record<string, unknown>
  message: string | null
}

export interface ApiError {
  message: string
  code: string
  errors: Record<string, string[]>
}

export interface Paginated<T> {
  data: T[]
  meta: {
    current_page: number
    per_page: number
    total: number | null
    last_page: number | null
  }
  message: string | null
}
