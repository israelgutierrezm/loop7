import { expect, test } from '@playwright/test'
import { artisan, connectFakeAccount, createBrand, expectToast, register } from './support'

test.describe('Competencia', () => {
  test('seguir a un competidor y compararse con él', async ({ page }, testInfo) => {
    test.setTimeout(180_000)
    const account = await register(page, 'Competencia E2E')
    // Professional: hasta 10 cuentas de la competencia.
    artisan(
      'tinker',
      `--execute=app(App\\Modules\\Billing\\Services\\SubscriptionService::class)->activatePlan(App\\Models\\User::where('email', '${account.email}')->firstOrFail()->ownedOrganizations()->firstOrFail(), App\\Modules\\Billing\\Models\\Plan::where('key', 'professional')->firstOrFail(), 'month', 'manual');`,
    )
    await createBrand(page, 'Café Aurora')
    await connectFakeAccount(page, 'Café Aurora')
    const brandId = /\/app\/brands\/([0-9A-Z]{26})/.exec(page.url())?.[1]
    expect(brandId).toBeTruthy()
    artisan('analytics:demo', brandId as string) // métricas de la cuenta propia

    await page.goto('/app/competitors')
    await expect(page.getByText('Aún no sigues a ningún competidor')).toBeVisible()
    await expect(page.getByText(/Sigues 0 de 10 cuentas/)).toBeVisible()

    // Alta: una cuenta que no existe da el error en su fila; luego la buena.
    await page.getByRole('button', { name: 'Añadir competidor' }).first().click()
    const dialog = page.getByRole('dialog', { name: 'Añadir competidor' })
    await dialog.getByLabel('Nombre del competidor').fill('Café Rival')
    await expect(dialog.getByLabel('Red de la cuenta 1')).toHaveValue('fake')
    await dialog.getByLabel('Cuenta 1').fill('@noexiste')
    await dialog.getByRole('button', { name: 'Guardar' }).click()
    await expect(dialog.getByText('No encontramos @noexiste en la red de prueba.')).toBeVisible()
    await dialog.getByLabel('Cuenta 1').fill('@CafeRival')
    await dialog.getByRole('button', { name: 'Guardar' }).click()
    await expectToast(page, 'Competidor añadido.')

    const table = page.getByRole('table', { name: /Comparación de tu marca con la competencia/ })
    await expect(table.getByRole('rowheader', { name: /Café Rival/ })).toBeVisible()
    await expect(table.getByRole('rowheader', { name: /Tú/ }).first()).toBeVisible()
    await expect(page.getByText(/Sigues 1 de 10 cuentas/)).toBeVisible()
    // Lo que mejor les funciona: sus publicaciones con más interacción.
    await expect(page.getByText(/Publicación \d de Caferival/).first()).toBeVisible()

    // Con días de historial, el gráfico de crecimiento.
    artisan(
      'tinker',
      "--execute=$a = App\\Modules\\Competitors\\Models\\CompetitorAccount::withoutGlobalScopes()->where('handle', 'caferival')->latest('id')->firstOrFail(); $f = (int) App\\Modules\\Competitors\\Models\\CompetitorSnapshot::withoutGlobalScopes()->where('competitor_account_id', $a->id)->value('followers'); foreach (range(1, 14) as $d) { App\\Modules\\Competitors\\Models\\CompetitorSnapshot::withoutGlobalScopes()->create(['organization_id' => $a->organization_id, 'competitor_account_id' => $a->id, 'date' => now()->subDays($d)->toDateString(), 'followers' => $f - $d * 40]); }",
    )
    await page.reload()
    await expect(page.getByRole('img', { name: /Crecimiento de seguidores de \d+ cuentas/ })).toBeVisible()
    await expect(table.getByRole('rowheader', { name: /Café Rival/ })).toBeVisible()

    // Ordenar por tasa de interacción.
    await table.getByRole('button', { name: 'Tasa de interacción' }).click()
    await expect(table.getByRole('columnheader', { name: /Tasa de interacción/ })).toHaveAttribute('aria-sort', 'descending')
    await page.screenshot({ path: testInfo.outputPath('competencia.png'), fullPage: true })

    // Renombrar en línea y actualizar.
    await page.getByRole('button', { name: 'Renombrar Café Rival' }).click()
    await page.getByLabel('Nombre del competidor').fill('Café Rival Centro')
    await page.getByRole('button', { name: 'Guardar' }).click()
    await expectToast(page, 'Competidor actualizado.')
    await page.getByRole('button', { name: 'Actualizar Café Rival Centro' }).click()
    await expectToast(page, 'Datos actualizados.')
  })
})
