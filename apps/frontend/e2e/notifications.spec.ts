import { expect, test } from '@playwright/test'
import { artisan, expectToast, register } from './support'

/** Prepara datos que la interfaz no puede crear (rol de plataforma, plan). */
function php(code: string): void {
  artisan('tinker', `--execute=${code}`)
}

test.describe('Canales de aviso', () => {
  test('SUPERADMIN configura push y WhatsApp, y cada persona elige por dónde recibir avisos', async ({ page }, testInfo) => {
    test.setTimeout(120_000)
    // La base E2E se comparte (y se reutiliza en los reintentos): canales sin configurar.
    php('App\\Modules\\Notifications\\Models\\NotificationChannel::query()->delete(); App\\Modules\\Notifications\\Models\\PushSubscription::query()->delete();')
    const account = await register(page, 'Avisos E2E')
    php(`App\\Models\\User::where('email', '${account.email}')->update(['is_platform_admin' => true]);`)

    // --- SUPERADMIN: claves VAPID y WhatsApp ---
    await page.goto('/platform/notification-channels')
    const push = page.locator('section', { has: page.getByRole('heading', { name: 'Avisos push del navegador' }) })
    await expect(push.getByText('Inactivo')).toBeVisible()
    await expect(push.getByRole('checkbox', { name: 'Activar avisos push' })).toBeDisabled()

    await push.getByRole('button', { name: 'Generar claves VAPID' }).click()
    await expectToast(page, 'Claves generadas.')
    await expect(push.locator('code')).toContainText(/^\s*B[A-Za-z0-9_-]{80,}/)
    await push.getByRole('checkbox', { name: 'Activar avisos push' }).check()
    await push.getByRole('button', { name: 'Guardar' }).click()
    await expectToast(page, 'Avisos push actualizados.')
    await expect(push.getByText('Activo', { exact: true })).toBeVisible()

    const whatsapp = page.locator('section', { has: page.getByRole('heading', { name: /WhatsApp/ }) })
    await whatsapp.getByLabel('Identificador del número').fill('1098765432')
    await whatsapp.getByLabel('Token de acceso (usuario del sistema)').fill('EAAG-e2e-token-9876')
    await whatsapp.getByLabel('Plantilla de avisos').fill('loop7_aviso')
    await whatsapp.getByLabel('Plantilla del código').fill('loop7_codigo')
    await whatsapp.getByRole('checkbox', { name: 'Activar avisos por WhatsApp' }).check()
    await whatsapp.getByRole('button', { name: 'Guardar' }).click()
    await expectToast(page, 'WhatsApp actualizado.')
    await expect(whatsapp.getByText('Activo', { exact: true })).toBeVisible()
    // El token es de solo escritura: sólo se ven sus últimos 4 caracteres.
    await expect(whatsapp.getByLabel('Token de acceso (usuario del sistema)')).toHaveValue('')
    await expect(whatsapp.getByLabel('Token de acceso (usuario del sistema)')).toHaveAttribute('placeholder', /••••9876/)
    await expect(page.getByText('EAAG-e2e-token')).toHaveCount(0)
    await page.screenshot({ path: testInfo.outputPath('canales-superadmin.png'), fullPage: true })

    // --- Mi perfil: el plan incluye WhatsApp ---
    php(`app(App\\Modules\\Billing\\Services\\SubscriptionService::class)->activatePlan(App\\Models\\User::where('email', '${account.email}')->firstOrFail()->ownedOrganizations()->firstOrFail(), App\\Modules\\Billing\\Models\\Plan::where('key', 'professional')->firstOrFail(), 'month', 'manual');`)
    // Como un usuario que ya permitió los avisos en el sitio.
    await page.context().grantPermissions(['notifications'])
    await page.goto('/app/profile#notificaciones')
    const section = page.locator('#notificaciones')
    await expect(section.getByRole('heading', { name: 'Notificaciones' })).toBeVisible()
    await expect(section.getByRole('columnheader', { name: 'Correo' })).toBeVisible()
    await expect(section.getByRole('columnheader', { name: 'Push' })).toBeVisible()
    // Sin número verificado no hay columna de WhatsApp.
    await expect(section.getByRole('columnheader', { name: 'WhatsApp' })).toHaveCount(0)

    await expect(section.getByText('Avisos push en este navegador')).toBeVisible()
    // Sin Push API o con las notificaciones bloqueadas (el Chromium sin interfaz de CI las
    // deniega siempre) se explica en lugar de ofrecer el botón.
    await expect(
      section.getByRole('button', { name: 'Activar en este navegador' })
        .or(section.getByText('Este navegador no admite avisos push'))
        .or(section.getByText('Bloqueaste los avisos de este sitio')),
    ).toBeVisible()
    await section.getByRole('button', { name: 'Añadir número' }).click()
    await expect(section.getByLabel('Número con código de país')).toBeVisible()
    await section.getByRole('button', { name: 'Cancelar' }).click()

    // Por defecto, los errores de publicación llegan también por push; se puede quitar.
    const failedPush = section.getByRole('checkbox', { name: 'Publicaciones con errores: recibir por Push' })
    await expect(failedPush).toBeChecked()
    const saved = page.waitForResponse((r) => r.url().includes('/me/notification-preferences') && r.request().method() === 'PUT')
    await failedPush.uncheck()
    expect((await saved).ok()).toBe(true)
    await section.screenshot({ path: testInfo.outputPath('notificaciones-perfil.png') })

    await page.reload()
    await expect(section.getByRole('checkbox', { name: 'Publicaciones con errores: recibir por Push' })).not.toBeChecked()
    await expect(section.getByRole('checkbox', { name: 'Publicaciones con errores: recibir por Correo' })).toBeChecked()
  })
})
