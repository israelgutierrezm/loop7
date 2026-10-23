import { expect, test } from '@playwright/test'
import { artisan, connectFakeAccount, createBrand, expectToast, register } from './support'

test.describe('Informes para clientes', () => {
  test('crear un informe, generar el PDF, abrirlo y enviarlo', async ({ page, request }) => {
    test.setTimeout(240_000)
    const account = await register(page, 'Informes E2E')
    // Professional: hasta 10 informes.
    artisan(
      'tinker',
      `--execute=app(App\\Modules\\Billing\\Services\\SubscriptionService::class)->activatePlan(App\\Models\\User::where('email', '${account.email}')->firstOrFail()->ownedOrganizations()->firstOrFail(), App\\Modules\\Billing\\Models\\Plan::where('key', 'professional')->firstOrFail(), 'month', 'manual');`,
    )
    await createBrand(page, 'Café Aurora')
    await connectFakeAccount(page, 'Café Aurora')
    const brandId = /\/app\/brands\/([0-9A-Z]{26})/.exec(page.url())?.[1]
    expect(brandId).toBeTruthy()
    artisan('analytics:demo', brandId as string)

    await page.goto('/app/reports')
    await expect(page.getByText('Aún no hay informes para esta marca')).toBeVisible()
    await expect(page.getByText(/Usas 0 de 10 informes/)).toBeVisible()

    // Alta con destinatario y envío mensual.
    await page.getByRole('button', { name: 'Nuevo informe' }).click()
    const dialog = page.getByRole('dialog', { name: 'Nuevo informe' })
    await dialog.getByLabel('Nombre', { exact: true }).fill('Informe mensual')
    await dialog.getByLabel('Periodo', { exact: true }).selectOption('last_30_days')
    await dialog.getByLabel(/Mensaje para el cliente/).fill('Hola, aquí va el resumen del mes.')
    const recipients = dialog.getByLabel(/Destinatarios/)
    await recipients.fill('ana@cliente.com')
    await recipients.press('Enter')
    await expect(dialog.getByText('ana@cliente.com')).toBeVisible()
    await dialog.getByLabel('Frecuencia').selectOption('monthly')
    await dialog.getByRole('button', { name: 'Guardar' }).click()
    await expectToast(page, 'Informe creado.')

    const card = page.getByRole('article', { name: 'Informe mensual' })
    await expect(card.getByText(/El día 1 de cada mes a las 08:00/)).toBeVisible()
    await expect(card.getByText('Para: ana@cliente.com')).toBeVisible()

    // Generar el PDF y abrirlo por su enlace firmado.
    await card.getByRole('button', { name: 'Generar PDF' }).click()
    const generate = page.getByRole('dialog', { name: 'Generar PDF' })
    await generate.getByRole('button', { name: 'Generar' }).click()
    await expectToast(page, /Generando el informe/)
    const link = card.getByRole('link', { name: /Abrir el PDF/ }).first()
    await expect(link).toBeVisible({ timeout: 60_000 })
    const href = (await link.getAttribute('href')) ?? ''
    expect(href).toContain('signature=')
    const pdf = await request.get(href)
    expect(pdf.status()).toBe(200)
    expect(pdf.headers()['content-type']).toContain('application/pdf')
    expect((await pdf.body()).subarray(0, 5).toString()).toBe('%PDF-')

    // Enviar ahora a los destinatarios.
    await card.getByRole('button', { name: 'Enviar ahora' }).click()
    const send = page.getByRole('dialog', { name: 'Enviar informe ahora' })
    await expect(send.getByText(/ana@cliente\.com/)).toBeVisible()
    await send.getByRole('button', { name: 'Enviar' }).click()
    await expect(card.getByText('Enviado a 1')).toBeVisible({ timeout: 60_000 })
  })
})
