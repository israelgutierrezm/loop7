import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import http, { fetchCsrfCookie, setTenantHeaders } from '@/services/http'
import { forgetPushSubscription } from '@/composables/usePushNotifications'
import { createSsoChallenge, takeSsoVerifier } from '@/utils/sso'
import type { Brand, Branding, Organization, SubscriptionSummary, User } from '@/types/models'

const ORG_STORAGE_KEY = 'loop7.currentOrganizationId'

export interface RegisterPayload {
  name: string
  email: string
  password: string
  password_confirmation: string
  organization_name?: string
  timezone?: string
  accept_terms: boolean
}

export interface LoginPayload {
  email: string
  password: string
  code?: string
  remember?: boolean
}

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const organizations = ref<Organization[]>([])
  const currentOrganization = ref<Organization | null>(null)
  const permissions = ref<string[]>([])
  const brands = ref<Brand[]>([])
  const entitlements = ref<Record<string, number | boolean>>({})
  const subscription = ref<SubscriptionSummary | null>(null)
  const branding = ref<Branding | null>(null)
  const impersonating = ref(false)
  const initialized = ref(false)

  const isAuthenticated = computed(() => user.value !== null)
  const isPlatformAdmin = computed(() => user.value?.is_platform_admin ?? false)

  function can(permission: string): boolean {
    return permissions.value.includes(permission)
  }

  /** ¿El plan vigente incluye la función? (la UI adapta flujos; el backend valida). */
  function hasFeature(key: string): boolean {
    return entitlements.value[key] === true
  }

  function hasRole(role: string): boolean {
    return currentOrganization.value?.roles?.includes(role) ?? false
  }

  function persistOrg(id: string | null): void {
    try {
      if (id) localStorage.setItem(ORG_STORAGE_KEY, id)
      else localStorage.removeItem(ORG_STORAGE_KEY)
    } catch {
      /* almacenamiento no disponible */
    }
  }

  function readPersistedOrg(): string | null {
    try {
      return localStorage.getItem(ORG_STORAGE_KEY)
    } catch {
      return null
    }
  }

  async function register(payload: RegisterPayload): Promise<void> {
    await fetchCsrfCookie()
    await http.post('/auth/register', payload)
    await fetchMe()
  }

  async function login(payload: LoginPayload): Promise<void> {
    await fetchCsrfCookie()
    await http.post('/auth/login', payload)
    await fetchMe()
  }

  /** Lleva al proveedor de identidad de la organización del correo (SSO). */
  async function startSso(email: string): Promise<void> {
    const challenge = await createSsoChallenge()
    await fetchCsrfCookie()
    const { data } = await http.post('/sso/discover', { email, challenge })
    const url = String(data.data.redirect_url ?? '')
    // Sólo direcciones web: nunca javascript: u otros esquemas.
    if (!/^https?:\/\//i.test(url)) throw new Error('sso_invalid_redirect')
    window.location.assign(url)
  }

  /** Vuelta del proveedor de identidad: canjea el código por la sesión. */
  async function completeSso(code: string): Promise<void> {
    const verifier = takeSsoVerifier()
    if (!verifier) throw new Error('sso_verifier_missing')
    await fetchCsrfCookie()
    const { data } = await http.post('/sso/exchange', { code, verifier })
    // Entra en la organización del SSO.
    currentOrganization.value = null
    persistOrg(data.data.organization)
    await fetchMe()
  }

  async function fetchMe(): Promise<void> {
    const { data } = await http.get('/me')
    user.value = data.data.user
    organizations.value = data.data.organizations
    impersonating.value = Boolean(data.data.impersonation)

    const desired = currentOrganization.value?.id ?? readPersistedOrg()
    const match = organizations.value.find((o) => o.id === desired)
    const target = match ?? organizations.value[0] ?? null

    if (target) {
      await selectOrganization(target.id)
    } else {
      currentOrganization.value = null
      setTenantHeaders(null)
    }

    initialized.value = true
  }

  async function selectOrganization(organizationId: string): Promise<void> {
    const org = organizations.value.find((o) => o.id === organizationId) ?? null
    currentOrganization.value = org
    setTenantHeaders(org?.id ?? null)
    persistOrg(org?.id ?? null)

    if (org?.status === 'suspended') {
      clearContext() // el backend rechaza su contexto; el panel muestra el aviso
    } else if (org) {
      await loadContext()
    }
  }

  /** La organización actual se suspendió con la sesión abierta. */
  function markCurrentSuspended(): void {
    if (!currentOrganization.value) return
    const id = currentOrganization.value.id
    currentOrganization.value = { ...currentOrganization.value, status: 'suspended' }
    organizations.value = organizations.value.map((o) => (o.id === id ? { ...o, status: 'suspended' } : o))
    clearContext()
  }

  function clearContext(): void {
    permissions.value = []
    brands.value = []
    entitlements.value = {}
    subscription.value = null
    branding.value = null
  }

  async function loadContext(): Promise<void> {
    const { data } = await http.get('/context')
    permissions.value = data.data.permissions ?? []
    brands.value = data.data.brands ?? []
    entitlements.value = data.data.entitlements ?? {}
    subscription.value = data.data.subscription ?? null
    branding.value = data.data.branding ?? null
    if (data.data.organization && currentOrganization.value) {
      const previousRoles = currentOrganization.value.roles
      const merged = { ...currentOrganization.value, ...data.data.organization }
      // Preserva los roles del listado (/me) si el contexto no los trae poblados.
      if ((!merged.roles || merged.roles.length === 0) && previousRoles?.length) {
        merged.roles = previousRoles
      }
      currentOrganization.value = merged
    }
  }

  async function init(): Promise<void> {
    if (initialized.value) return
    try {
      await fetchMe()
    } catch {
      reset()
    } finally {
      initialized.value = true
    }
  }

  async function logout(): Promise<void> {
    try {
      // Antes de cerrar la sesión: el backend sólo deja borrar las suscripciones propias.
      await forgetPushSubscription()
      await http.post('/auth/logout')
    } finally {
      reset()
    }
  }

  function reset(): void {
    user.value = null
    organizations.value = []
    currentOrganization.value = null
    clearContext()
    impersonating.value = false
    setTenantHeaders(null)
    persistOrg(null)
  }

  return {
    user,
    organizations,
    currentOrganization,
    permissions,
    brands,
    entitlements,
    subscription,
    branding,
    impersonating,
    initialized,
    isAuthenticated,
    isPlatformAdmin,
    can,
    hasFeature,
    hasRole,
    register,
    login,
    startSso,
    completeSso,
    fetchMe,
    selectOrganization,
    markCurrentSuspended,
    loadContext,
    init,
    logout,
    reset,
  }
})
