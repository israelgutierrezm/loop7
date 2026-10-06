/** Inicio de sesión único (SAML) de la organización — docs/03. */

export interface SsoDomain {
  id: string
  domain: string
  verified: boolean
  verified_at: string | null
  last_checked_at: string | null
  txt_name: string
  txt_value: string
}

export interface SsoCertificate {
  subject: string
  expires_at: string
}

export interface SsoConnection {
  is_enabled: boolean
  enforced: boolean
  configured: boolean
  idp_entity_id: string | null
  idp_sso_url: string | null
  idp_certificate: string | null
  certificates: SsoCertificate[]
  jit_provisioning: boolean
  default_role: string
  email_attribute: string | null
  name_attribute: string | null
  last_login_at: string | null
}

export interface SsoSettings {
  available: boolean
  service_provider: { entity_id: string; acs_url: string; metadata_url: string }
  connection: SsoConnection
  domains: SsoDomain[]
  max_domains: number
}

export interface SsoTestResult {
  ok: boolean
  reason?: string
  detail?: string
  /** Qué pasaría al entrar: ya es miembro o se daría de alta. */
  outcome?: 'member' | 'provision'
  email: string | null
  name: string | null
  name_id: string | null
  attributes: Record<string, string>
}
