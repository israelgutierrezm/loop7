import axios, { type AxiosInstance } from 'axios'

/**
 * Cliente HTTP para la API v1.
 *
 * - withCredentials + withXSRFToken: autenticación SPA por cookies de Sanctum.
 * - Inyecta cabeceras de tenant (X-Organization / X-Brand) en cada petición.
 */
const http: AxiosInstance = axios.create({
  baseURL: '/api/v1',
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
})

let currentOrganizationId: string | null = null
let currentBrandId: string | null = null
let onUnauthorized: (() => void) | null = null
let onImpersonationExpired: (() => void) | null = null
let onAccountBlocked: (() => void) | null = null
let onOrganizationSuspended: (() => void) | null = null
let onOrganizationLost: (() => void) | null = null

export function setTenantHeaders(organizationId: string | null, brandId: string | null = null): void {
  currentOrganizationId = organizationId
  currentBrandId = brandId
}

export function setUnauthorizedHandler(handler: () => void): void {
  onUnauthorized = handler
}

/** La impersonación caducó: la sesión ya volvió al administrador. */
export function setImpersonationExpiredHandler(handler: () => void): void {
  onImpersonationExpired = handler
}

/** Soporte bloqueó la cuenta con la sesión abierta: el backend ya la cerró. */
export function setAccountBlockedHandler(handler: () => void): void {
  onAccountBlocked = handler
}

/** SUPERADMIN suspendió la organización actual con la sesión abierta. */
export function setOrganizationSuspendedHandler(handler: () => void): void {
  onOrganizationSuspended = handler
}

/** El usuario dejó de ser miembro activo de la organización actual (lo quitaron o suspendieron). */
export function setOrganizationLostHandler(handler: () => void): void {
  onOrganizationLost = handler
}

http.interceptors.request.use((config) => {
  if (currentOrganizationId) {
    config.headers.set('X-Organization', currentOrganizationId)
  }
  if (currentBrandId) {
    config.headers.set('X-Brand', currentBrandId)
  }
  return config
})

http.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error?.response?.status
    const code = error?.response?.data?.code
    const url: string = error?.config?.url ?? ''
    if (status === 401 && code === 'impersonation_expired' && onImpersonationExpired) {
      onImpersonationExpired()
    } else if (status === 403 && code === 'account_blocked' && !url.includes('/auth/login') && onAccountBlocked) {
      // En el login el propio formulario muestra el aviso.
      onAccountBlocked()
    } else if (status === 403 && code === 'organization_suspended' && onOrganizationSuspended) {
      onOrganizationSuspended()
    } else if (status === 403 && code === 'organization_not_resolved' && onOrganizationLost) {
      onOrganizationLost()
    } else if (status === 401 && !url.includes('/auth/login') && onUnauthorized) {
      // 401 en cualquier endpoint distinto del login implica sesión expirada.
      onUnauthorized()
    }
    return Promise.reject(error)
  },
)

/** Obtiene la cookie CSRF de Sanctum antes de operaciones autenticadas. */
export async function fetchCsrfCookie(): Promise<void> {
  await axios.get('/sanctum/csrf-cookie', { withCredentials: true })
}

export default http
