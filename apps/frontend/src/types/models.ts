export interface User {
  id: string
  name: string
  email: string
  locale: string
  timezone: string
  avatar_path: string | null
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
}

export interface Brand {
  id: string
  name: string
  slug: string
  website: string | null
  description: string | null
  logo_path: string | null
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

export interface OrganizationContext {
  organization: Organization
  permissions: string[]
  brands: Brand[]
  entitlements: Record<string, number | boolean>
  subscription: SubscriptionSummary | null
}

export interface MemberEntry {
  user: User
  membership: Membership & { is_owner: boolean }
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
