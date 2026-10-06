import { readFileSync } from 'node:fs'
import { inflateRawSync } from 'node:zlib'
import { expect, test, type Page } from '@playwright/test'
import { artisan, artisanOutput, expectToast, logout, register } from './support'

// Los mismos que el IdP de prueba del backend (tests/Support/SamlIdp.php).
const IDP_ENTITY_ID = 'https://idp.example.test/metadata'
const IDP_SSO_URL = 'https://idp.example.test/sso'

/**
 * Proveedor de identidad simulado: intercepta la petición SAML del navegador y
 * devuelve una página que envía al ACS una respuesta firmada de verdad (la
 * firma el IdP de prueba del backend con su clave guardada en `keyDir`).
 */
async function fakeIdp(page: Page, keyDir: string, email: string): Promise<void> {
  await page.route(`${IDP_SSO_URL}**`, async (route) => {
    const url = new URL(route.request().url())
    const request = inflateRawSync(Buffer.from(url.searchParams.get('SAMLRequest') ?? '', 'base64')).toString()
    const requestId = /\sID="([^"]+)"/.exec(request)?.[1] ?? ''
    const acs = /AssertionConsumerServiceURL="([^"]+)"/.exec(request)?.[1] ?? ''
    const audience = /<saml:Issuer>([^<]+)<\/saml:Issuer>/.exec(request)?.[1] ?? ''
    const output = artisanOutput(
      'tinker',
      `--execute=echo Tests\\Support\\SamlIdp::stored('${keyDir}')->response('${acs}', '${audience}', '${requestId}', '${email}', ['email' => '${email}', 'displayName' => 'Nora Ruiz']);`,
    )
    const samlResponse = /[A-Za-z0-9+/=]{200,}/.exec(output)?.[0] ?? ''
    const relayState = url.searchParams.get('RelayState') ?? ''
    await route.fulfill({
      contentType: 'text/html',
      body: `<!doctype html><form method="post" action="${acs}"><input type="hidden" name="SAMLResponse" value="${samlResponse}"><input type="hidden" name="RelayState" value="${relayState}"></form><script>document.forms[0].submit()</script>`,
    })
  })
}

test.describe('Inicio de sesión único (SAML)', () => {
  test('configurar el SSO, probar la conexión y entrar con el proveedor de identidad', async ({ page }, testInfo) => {
    test.setTimeout(240_000)
    const account = await register(page, 'SSO E2E')
    const domain = `empresa${Date.now()}.test`
    const ssoEmail = `nora@${domain}`
    const keyDir = testInfo.outputPath('idp').replaceAll('\\', '/')
    // Enterprise: incluye el SSO.
    artisan(
      'tinker',
      `--execute=app(App\\Modules\\Billing\\Services\\SubscriptionService::class)->activatePlan(App\\Models\\User::where('email', '${account.email}')->firstOrFail()->ownedOrganizations()->firstOrFail(), App\\Modules\\Billing\\Models\\Plan::where('key', 'enterprise')->firstOrFail(), 'month', 'manual');`,
    )
    artisan('tinker', `--execute=Tests\\Support\\SamlIdp::stored('${keyDir}');`)
    const certificate = readFileSync(`${keyDir}/idp.crt`, 'utf8')

    await page.goto('/app/settings')
    const sso = page.getByRole('region', { name: 'Inicio de sesión único (SSO)' })
    await expect(sso.getByRole('textbox', { name: 'URL de respuesta (ACS)' })).toHaveValue(/\/api\/v1\/sso\/[0-9A-Z]{26}\/acs$/)

    // Dominio: se añade y, sin el registro TXT, no se verifica.
    await sso.getByLabel('Dominio', { exact: true }).fill(domain)
    await sso.getByRole('button', { name: 'Añadir dominio' }).click()
    await expectToast(page, /Dominio añadido/)
    await expect(sso.getByRole('textbox', { name: `Valor TXT para ${domain}` })).toHaveValue(/^loop7-verification=/)
    await sso.getByRole('button', { name: 'Verificar' }).click()
    await expectToast(page, /Aún no encontramos el registro TXT/)
    // El DNS no se puede publicar en la prueba: se marca como lo haría la comprobación.
    artisan(
      'tinker',
      `--execute=App\\Modules\\Sso\\Models\\OrganizationDomain::withoutGlobalScopes()->where('domain', '${domain}')->firstOrFail()->forceFill(['verified_at' => now(), 'verified_domain' => '${domain}'])->save();`,
    )
    await page.reload()
    await expect(sso.getByText('Verificado', { exact: true })).toBeVisible()

    // Conexión con el IdP, alta automática y activación.
    await sso.getByLabel('Identificador del proveedor (Entity ID / emisor)').fill(IDP_ENTITY_ID)
    await sso.getByLabel('URL de inicio de sesión (SSO, HTTP-Redirect)').fill(IDP_SSO_URL)
    await sso.getByLabel('Certificado de firma (X.509, PEM)').fill(certificate)
    await sso.getByRole('checkbox', { name: /Alta automática/ }).check()
    await sso.getByLabel('Rol por defecto', { exact: true }).selectOption('CONTENT_CREATOR')
    await sso.getByRole('checkbox', { name: /Activar el inicio de sesión único/ }).check()
    await sso.getByRole('button', { name: 'Guardar SSO' }).click()
    await expectToast(page, 'Configuración de SSO guardada.')
    await expect(sso.getByText('Activo', { exact: true })).toBeVisible()
    await expect(sso.getByText(/idp\.example\.test · caduca el/)).toBeVisible()

    // Prueba de conexión: vuelve a Configuración con lo que llegó, sin iniciar sesión.
    await fakeIdp(page, keyDir, ssoEmail)
    await sso.getByRole('button', { name: 'Probar conexión' }).click()
    await expect(sso.getByText('La conexión funciona')).toBeVisible({ timeout: 30_000 })
    await expect(sso.getByText(/se le daría de alta automáticamente/)).toBeVisible()
    await expect(sso.getByText(ssoEmail).first()).toBeVisible()
    expect(page.url()).not.toContain('sso_test')

    // Un correo sin SSO no sale hacia ningún proveedor.
    await logout(page)
    await page.getByRole('button', { name: 'Continuar con SSO' }).click()
    await page.getByLabel('Correo de trabajo').fill('alguien@otra-empresa.test')
    await page.getByRole('button', { name: 'Continuar con SSO' }).click()
    await expect(page.getByText('Tu correo no tiene inicio de sesión único configurado. Entra con tu contraseña.')).toBeVisible()

    // Entrar con SSO: Nora no tenía cuenta y se da de alta con el rol por defecto.
    await page.getByLabel('Correo de trabajo').fill(ssoEmail)
    await page.getByRole('button', { name: 'Continuar con SSO' }).click()
    await expect(page).toHaveURL(/\/app$/, { timeout: 30_000 })
    await expect(page.getByRole('heading', { name: /Hola, Nora/ })).toBeVisible()

    // El código ya se gastó: volver al enlace de retorno no inicia otra sesión.
    await logout(page)
    await page.goto('/sso/callback#code=' + 'x'.repeat(64))
    await expect(page.getByText('El inicio de sesión con SSO caducó o ya se usó. Vuelve a intentarlo.')).toBeVisible()
  })
})
