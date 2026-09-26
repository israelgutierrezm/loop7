export interface User {
  id: string
  name: string
  email: string
  locale: string
  timezone: string
  is_platform_admin: boolean
  two_factor_enabled: boolean
  email_verified: boolean
  created_at: string | null
}

export interface Membership {
  status: string
  all_brands_access: boolean
  joined_at: string | null
}

export interface Organization {
  id: string
  name: string
  slug: string
  status: string
  billing_email: string | null
  tax_id?: string | null
  country: string | null
  timezone: string
  locale: string
  is_owner: boolean
  trial_ends_at: string | null
  created_at: string | null
  membership?: Membership
  roles?: string[]
  /** Nombre visible de cada rol (los personalizados tienen un nombre interno custom_…). */
  role_labels?: string[]
}

/** Rol de la organización: predefinido (docs/04) o personalizado. */
export interface RoleDefinition {
  value: string
  label: string
  description: string | null
  custom: boolean
  permissions: string[]
}

export interface PermissionGroup {
  key: string
  label: string
  permissions: { key: string; label: string; owner_only: boolean }[]
}

export interface Brand {
  id: string
  name: string
  slug: string
  website: string | null
  description: string | null
  logo: { id: string; url: string } | null
  primary_color: string | null
  secondary_color: string | null
  timezone: string
  status: string
  created_at: string | null
}

export interface SubscriptionSummary {
  status: string
  status_label: string
  grants_access: boolean
  plan_name: string | null
  trial_ends_at: string | null
  current_period_end: string | null
  cancel_at_period_end: boolean
}

/** Marca blanca vigente de la organización (null = marca de la plataforma). */
export interface Branding {
  name: string
  color: string | null
  logo_url: string | null
}

export interface MemberEntry {
  user: User
  /** brands: marcas con acceso explícito (sólo cuenta si all_brands_access es false). */
  membership: Membership & { is_owner: boolean; brands: string[] }
  roles: string[]
}

export interface Invitation {
  id: string
  email: string
  role: string
  status: string
  expires_at: string | null
  accepted_at: string | null
  created_at: string | null
}

export interface SocialProviderOption {
  key: string
  name: string
  capabilities?: Record<string, boolean>
}

export interface SocialDestination {
  id: string
  external_id?: string
  name: string
  type: string
}

export interface SocialConnection {
  id: string
  provider: string
  status: string
  status_label: string
  needs_attention: boolean
  account_name: string | null
  is_manual: boolean
  token_expires_at?: string | null
  destinations: SocialDestination[]
  created_at?: string | null
}
