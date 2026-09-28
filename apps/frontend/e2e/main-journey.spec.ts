import { expect, test, type Page } from '@playwright/test'
import { expectToast, register } from './support'

/**
 * Recorrido principal (CLAUDE.md, «Testing»): alta → marca → cuenta social
 * simulada → contenido → aprobación → programación y publicación.
 */

async function createBrand(page: Page, name: string): Promise<void> {
  await page.goto('/app/brands')
  await page.getByRole('button', { name: 'Nueva marca' }).first().click()
  await page.getByLabel('Nombre').fill(name)
  await page.getByRole('button', { name: 'Crear marca' }).click()
  await expectToast(page, 'Marca creada.')
}

async function connectFakeAccount(page: Page, brand: string): Promise<void> {
  await page.getByRole('link', { name: new RegExp(brand) }).first().click()
  await page.getByRole('tab', { name: 'Redes' }).click()
  // El proveedor de prueba vuelve directo al callback OAuth con un código simulado.
  await page.getByRole('button', { name: /Conectar Proveedor de prueba/ }).click()
  await expect(page).toHaveURL(/social=connected|\/app\/brands\//)
  await expectToast(page, 'Cuenta conectada.')
  await expect(page.getByRole('button', { name: 'Desconectar' })).toBeVisible()
}

async function createContent(page: Page, title: string): Promise<void> {
  await page.goto('/app/content')
  await page.getByRole('button', { name: 'Nuevo' }).click()
  const dialog = page.getByRole('dialog', { name: 'Nuevo contenido' })
  await dialog.getByLabel('Título').fill(title)
  await dialog.getByLabel('Texto base').fill('¡Nuevo tueste de temporada! Pásate a probarlo.')
  await dialog.getByRole('button', { name: 'Crear' }).click()
  await expect(page).toHaveURL(/\/app\/content\/[0-9A-Z]{26}/)
  await expect(page.getByRole('heading', { name: title })).toBeVisible()
}

async function addFakeVariant(page: Page): Promise<void> {
  await page.getByLabel('Red social').selectOption({ label: 'Proveedor de prueba' })
  await page.getByRole('button', { name: 'Añadir red' }).click()
  await expectToast(page, 'Variante añadida.')
}

/** Con aprobaciones en el plan: enviar y aprobar; sin ellas: marcar como listo. */
async function approve(page: Page): Promise<void> {
  const submit = page.getByRole('button', { name: 'Enviar a revisión' })
  if (await submit.isVisible()) {
    await submit.click()
    await expectToast(page, 'Enviado a revisión.')
    await page.getByRole('button', { name: 'Aprobar' }).click()
  } else {
    await page.getByRole('button', { name: 'Marcar como listo' }).click()
  }
  await expect(page.getByText('Aprobado', { exact: true }).first()).toBeVisible()
}

test.describe('Recorrido principal', () => {
  test('crear marca, conectar cuenta simulada, crear contenido, aprobar y programar', async ({ page }) => {
    await register(page, 'Tostadores del Norte')
    await createBrand(page, 'Café Norte')
    await connectFakeAccount(page, 'Café Norte')

    await createContent(page, 'Tueste de otoño')
    await addFakeVariant(page)
    await approve(page)

    // Programar para mañana a las 10:00 (hora local del navegador).
    const tomorrow = new Date(Date.now() + 24 * 3600 * 1000)
    const pad = (n: number) => String(n).padStart(2, '0')
    const when = `${tomorrow.getFullYear()}-${pad(tomorrow.getMonth() + 1)}-${pad(tomorrow.getDate())}T10:00`
    await page.getByLabel('Fecha y hora de publicación').fill(when)
    await page.getByRole('button', { name: 'Programar', exact: true }).click()
    await expectToast(page, 'Programado.')
    await expect(page.getByText('Programado', { exact: true }).first()).toBeVisible()

    // Aparece en el listado con su estado.
    await page.goto('/app/content')
    const row = page.getByRole('link', { name: /Tueste de otoño/ })
    await expect(row).toBeVisible()
    await expect(row.getByText('Programado')).toBeVisible()

    // Y el buscador de comandos (Ctrl+K) lo encuentra y lo abre.
    await page.goto('/app')
    await expect(page.getByRole('heading', { name: /Hola, Ana/ })).toBeVisible()
    await page.keyboard.press('Control+k')
    const palette = page.getByRole('dialog', { name: 'Buscador de comandos' })
    await palette.getByRole('combobox').fill('Tueste')
    await expect(palette.getByRole('option', { name: /Tueste de otoño/ })).toBeVisible()
    await page.keyboard.press('Enter')
    await expect(page).toHaveURL(/\/app\/content\/[0-9A-Z]{26}/)
    await expect(page.getByRole('heading', { name: 'Tueste de otoño' })).toBeVisible()
  })

  test('publicar ahora en la cuenta simulada', async ({ page }) => {
    await register(page, 'Panadería E2E')
    await createBrand(page, 'Pan Rústico')
    await connectFakeAccount(page, 'Pan Rústico')

    await createContent(page, 'Hogaza del día')
    await addFakeVariant(page)
    await approve(page)

    await page.getByRole('button', { name: /Publicar ahora/ }).click()
    // Confirmación del diálogo.
    await page.getByRole('dialog').getByRole('button', { name: /Publicar/ }).click()
    await expect(page.getByText('Publicado', { exact: true }).first()).toBeVisible()
  })
})
