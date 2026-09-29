import { expect, test } from '@playwright/test'
import { addFakeVariant, approve, connectFakeAccount, createBrand, createContent, expectToast, register } from './support'

/**
 * Recorrido principal (CLAUDE.md, «Testing»): alta → marca → cuenta social
 * simulada → contenido → aprobación → programación y publicación.
 */

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
