/**
 * Inicio de sesión único (SAML): verificador del navegador y mensajes de error.
 *
 * Como en PKCE, el navegador que empieza el inicio de sesión guarda un secreto
 * (sessionStorage) y sólo envía su SHA-256; al volver del proveedor de
 * identidad presenta el secreto para canjear el código. Así nadie puede
 * terminar en tu navegador un inicio de sesión que empezó otra persona.
 */

const VERIFIER_KEY = 'loop7.sso.verifier'

const SSO_ERRORS: Record<string, string> = {
  expired: 'El inicio de sesión con SSO caducó o ya se usó. Vuelve a intentarlo.',
  not_enabled: 'El inicio de sesión único no está disponible para tu organización.',
  invalid_response: 'No pudimos validar la respuesta de tu proveedor de identidad. Si se repite, avisa a quien administra tu organización.',
  no_email: 'Tu proveedor de identidad no envió tu correo. Avisa a quien administra tu organización.',
  domain_not_verified: 'Tu correo no es de un dominio verificado de la organización.',
  not_member: 'Aún no eres miembro de la organización en Loop7. Pide una invitación a quien la administra.',
  suspended: 'Tu acceso a la organización está suspendido.',
  seats: 'La organización no tiene plazas libres en su plan. Avisa a quien la administra.',
  blocked: 'Tu cuenta no puede iniciar sesión. Escribe a soporte.',
  platform_admin: 'Las cuentas de administración de la plataforma no entran con SSO.',
}

export function ssoErrorMessage(reason: string | null | undefined): string {
  return (reason && SSO_ERRORS[reason]) || 'No se pudo iniciar sesión con SSO. Vuelve a intentarlo.'
}

function base64Url(bytes: Uint8Array): string {
  let binary = ''
  bytes.forEach((byte) => {
    binary += String.fromCharCode(byte)
  })
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

async function sha256Hex(value: string): Promise<string> {
  const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(value))
  return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, '0')).join('')
}

/** Crea y guarda el verificador; devuelve su SHA-256 (hex) para el backend. */
export async function createSsoChallenge(): Promise<string> {
  const verifier = base64Url(crypto.getRandomValues(new Uint8Array(32)))
  sessionStorage.setItem(VERIFIER_KEY, verifier)
  return sha256Hex(verifier)
}

/** Recupera el verificador (un solo uso). */
export function takeSsoVerifier(): string | null {
  try {
    const verifier = sessionStorage.getItem(VERIFIER_KEY)
    sessionStorage.removeItem(VERIFIER_KEY)
    return verifier
  } catch {
    return null
  }
}
