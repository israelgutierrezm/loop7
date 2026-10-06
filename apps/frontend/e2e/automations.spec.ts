import { expect, test } from '@playwright/test'
import { addFakeVariant, approve, artisan, connectFakeAccount, createBrand, createContent, expectToast, register } from './support'

test.describe('Editor visual de automatizaciones', () => {
  test('armar un flujo con condición, probarlo y verlo ejecutado al publicar', async ({ page }, testInfo) => {
    test.setTimeout(240_000)
    const account = await register(page, 'Flujos E2E')
    // Las automatizaciones son de Professional en adelante.
    artisan(
      'tinker',
      `--execute=app(App\\Modules\\Billing\\Services\\SubscriptionService::class)->activatePlan(App\\Models\\User::where('email', '${account.email}')->firstOrFail()->ownedOrganizations()->firstOrFail(), App\\Modules\\Billing\\Models\\Plan::where('key', 'professional')->firstOrFail(), 'month', 'manual');`,
    )
    await createBrand(page, 'Café Flujo')
    await connectFakeAccount(page, 'Café Flujo')

    // --- Nueva desde una plantilla ---
    await page.goto('/app/automations')
    await page.getByRole('button', { name: 'Nueva automatización' }).first().click()
    await page.getByRole('dialog', { name: 'Nueva automatización' }).getByRole('button', { name: /Avisar al equipo al publicar/ }).click()
    await expect(page).toHaveURL(/\/app\/automations\/nueva\?plantilla=aviso-publicacion/)
    await expect(page.getByLabel('Nombre de la automatización')).toHaveValue('Avisar al equipo al publicar')
    const canvas = page.getByRole('region', { name: 'Diagrama de la automatización' })
    await expect(canvas.getByRole('button', { name: /^Disparador: Cuando se publica contenido/ })).toBeVisible()
    await expect(canvas.getByRole('button', { name: /^Acción: Avisar al equipo/ })).toBeVisible()

    // Una condición antes del aviso: el aviso pasa a su camino «Sí».
    await canvas.getByRole('button', { name: 'Añadir un paso aquí' }).first().click()
    await page.getByRole('menuitem', { name: /Condición/ }).click()
    const inspector = page.locator('section[aria-labelledby="inspector-title"]')
    await inspector.getByLabel('Campo de la condición 1').fill('content_title')
    await inspector.getByLabel('Operador de la condición 1').selectOption({ label: 'contiene' })
    await inspector.getByLabel('Valor de la condición 1').fill('Oferta')
    await expect(canvas.getByRole('button', { name: /^Condición\. Si content_title contiene «Oferta»/ })).toBeVisible()

    // En «No», otro aviso.
    await canvas.getByRole('button', { name: 'Añadir un paso aquí' }).last().click()
    await page.getByRole('menuitem', { name: /Acción/ }).click()
    await inspector.getByLabel('Mensaje del aviso').fill('Sin oferta: ')
    await inspector.getByRole('button', { name: 'Insertar {content_title}' }).click()
    await expect(inspector.getByLabel('Mensaje del aviso')).toHaveValue('Sin oferta: {content_title}')

    await page.getByRole('button', { name: 'Guardar' }).click()
    await expectToast(page, 'Automatización creada.')
    await expect(page).toHaveURL(/\/app\/automations\/[0-9A-Z]{26}$/)
    await page.screenshot({ path: testInfo.outputPath('editor.png'), fullPage: true })

    // --- Probar con los datos de ejemplo (el título no lleva «Oferta») ---
    await page.getByRole('button', { name: 'Probar' }).first().click()
    const dialog = page.getByRole('dialog', { name: 'Probar la automatización' })
    await expect(dialog.getByLabel('content_title')).toHaveValue('Lanzamiento de otoño')
    await dialog.getByRole('button', { name: 'Probar' }).click()
    await expectToast(page, /Se harían 1 acción/)
    await expect(page.getByText('Prueba con datos de ejemplo (no se ejecutó nada)').first()).toBeVisible()
    await expect(canvas.getByText('Siguió por «No»')).toBeVisible()
    await expect(canvas.getByText('Sin oferta: Lanzamiento de otoño')).toBeVisible()
    await page.screenshot({ path: testInfo.outputPath('prueba.png'), fullPage: true })
    const editorUrl = page.url()

    // --- Publicar algo con «Oferta»: se ejecuta por el camino «Sí» ---
    await createContent(page, 'Oferta relámpago')
    await addFakeVariant(page)
    await approve(page)
    await page.getByRole('button', { name: /Publicar ahora/ }).click()
    await page.getByRole('dialog').getByRole('button', { name: /Publicar/ }).click()
    await expect(page.getByText('Publicado', { exact: true }).first()).toBeVisible()

    await page.goto(editorUrl)
    const history = page.locator('section[aria-labelledby="runs-title"]')
    await history.getByRole('button', { name: /Correcta/ }).first().click()
    await expect(page.getByText(/Ejecución del /).first()).toBeVisible()
    await expect(canvas.getByText('Siguió por «Sí»')).toBeVisible()
    await expect(canvas.getByText(/Aviso enviado a \d+ persona/)).toBeVisible()
    await page.screenshot({ path: testInfo.outputPath('ejecucion.png'), fullPage: true })

    // Sin cambios no avisa al salir; la lista muestra los pasos.
    await page.getByRole('link', { name: 'Automatizaciones' }).first().click()
    await expect(page).toHaveURL(/\/app\/automations$/)
    await expect(page.getByText(/Cuando se publica contenido · 3 paso\(s\)/)).toBeVisible()
  })

  test('editar la estructura: esperas, mover, arrastrar y quitar una condición', async ({ page }) => {
    test.setTimeout(240_000)
    const account = await register(page, 'Estructura E2E')
    artisan(
      'tinker',
      `--execute=app(App\\Modules\\Billing\\Services\\SubscriptionService::class)->activatePlan(App\\Models\\User::where('email', '${account.email}')->firstOrFail()->ownedOrganizations()->firstOrFail(), App\\Modules\\Billing\\Models\\Plan::where('key', 'professional')->firstOrFail(), 'month', 'manual');`,
    )

    await page.goto('/app/automations/nueva?plantilla=en-blanco')
    const canvas = page.getByRole('region', { name: 'Diagrama de la automatización' })
    const inspector = page.locator('section[aria-labelledby="inspector-title"]')
    const connectors = canvas.getByRole('button', { name: 'Añadir un paso aquí' })
    const node = (text: string) => canvas.locator('[data-step]').filter({ hasText: text })
    // Orden de los pasos en el diagrama (por su texto principal).
    const order = () => canvas.locator('[data-step]:not([data-step="trigger"])').evaluateAll((els) =>
      els.map((el) => (el.textContent ?? '').match(/Primero|Segundo|Esperar 2 horas|Condición/)?.[0] ?? '?'),
    )

    await page.getByLabel('Nombre de la automatización').fill('Estructura')
    await inspector.getByLabel('Mensaje del aviso').fill('Primero')

    await connectors.last().click()
    await page.getByRole('menuitem', { name: /Esperar/ }).click()
    await inspector.getByLabel('Cantidad').fill('2')
    await inspector.getByLabel('Unidad').selectOption({ label: 'horas' })

    await connectors.last().click()
    await page.getByRole('menuitem', { name: /Acción/ }).click()
    await inspector.getByLabel('Mensaje del aviso').fill('Segundo')
    await expect.poll(order).toEqual(['Primero', 'Esperar 2 horas', 'Segundo'])

    // Subir «Segundo» deja la espera al final: el guardado lo señala en ese paso.
    await node('Segundo').hover()
    await node('Segundo').getByRole('button', { name: /^Subir/ }).click()
    await expect.poll(order).toEqual(['Primero', 'Segundo', 'Esperar 2 horas'])
    await page.getByRole('button', { name: 'Guardar' }).click()
    await expectToast(page, 'Revisa los pasos marcados en el diagrama.')
    await expect(node('Esperar 2 horas').getByText('Añade un paso después de la espera.')).toBeVisible()

    // Arrastrar la espera entre «Primero» y «Segundo».
    const dataTransfer = await page.evaluateHandle(() => new DataTransfer())
    await node('Esperar 2 horas').getByRole('button').first().dispatchEvent('dragstart', { dataTransfer })
    const zones = canvas.getByText('Soltar aquí')
    await expect(zones.first()).toBeVisible()
    await zones.nth(1).dispatchEvent('dragover', { dataTransfer })
    await zones.nth(1).dispatchEvent('drop', { dataTransfer })
    await expect.poll(order).toEqual(['Primero', 'Esperar 2 horas', 'Segundo'])

    // Una condición al principio se lleva los pasos siguientes a «Sí».
    await connectors.first().click()
    await page.getByRole('menuitem', { name: /Condición/ }).click()
    await inspector.getByLabel('Campo de la condición 1').fill('content_status')
    await inspector.getByLabel('Operador de la condición 1').selectOption({ label: 'no está vacío' })
    await expect(inspector.getByLabel('Valor de la condición 1')).toHaveCount(0)
    await expect.poll(order).toEqual(['Condición', 'Primero', 'Esperar 2 horas', 'Segundo'])
    await expect(canvas.getByText('No hace nada')).toBeVisible() // el camino «No» está vacío

    // Quitarla conservando lo de «Sí».
    await node('Condición').hover()
    await node('Condición').getByRole('button', { name: /^Quitar/ }).click()
    const confirmDialog = page.getByRole('dialog', { name: 'Quitar la condición' })
    await expect(confirmDialog.getByRole('checkbox', { name: /Conservar los pasos de «Sí»/ })).toBeChecked()
    await confirmDialog.getByRole('button', { name: 'Quitar' }).click()
    await expect.poll(order).toEqual(['Primero', 'Esperar 2 horas', 'Segundo'])

    await page.getByRole('button', { name: 'Guardar' }).click()
    await expectToast(page, 'Automatización creada.')
    await expect(page.getByText('Cambios sin guardar')).toHaveCount(0)
  })
})
