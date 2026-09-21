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

export interface OrganizationContext {
  organization: Organization
  permissions: string[]
  brands: Brand[]
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
