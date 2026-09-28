import { expect, test } from '@playwright/test'
import { login, logout, register } from './support'

test.describe('Acceso', () => {
  test('registro con organización, cierre de sesión y nuevo inicio de sesión', async ({ page }) => {
    const account = await register(page, 'Café del Puerto')

    // La organización creada en el registro es la actual.
    await expect(page.getByText(/Café del Puerto/).first()).toBeVisible()

    await logout(page)
    // Sin sesión, la app redirige al login conservando el destino.
    await page.goto('/app/brands')
    await expect(page).toHaveURL(/\/login\?redirect=/)

    await login(page, account.email)
    await expect(page.getByRole('heading', { name: /Hola, Ana/ })).toBeVisible()
  })

  test('credenciales incorrectas muestran un error y no entran', async ({ page }) => {
    await page.goto('/login')
    await page.getByLabel('Correo electrónico').fill('nadie@loop7.test')
    await page.getByLabel('Contraseña').fill('No-Existe-123')
    await page.getByRole('button', { name: 'Entrar' }).click()

    await expect(page.getByText(/credenciales|incorrect/i).first()).toBeVisible()
    await expect(page).toHaveURL(/\/login/)
  })
})
