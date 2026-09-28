import { expect, type Page } from '@playwright/test'

/** Contraseña de las cuentas que crean las pruebas (sólo existe en la base E2E). */
export const E2E_PASSWORD = 'E2e-Loop7-2026'

export interface Account {
  name: string
  email: string
  organization: string
}

/**
 * Crea una cuenta nueva con su organización desde el formulario de registro
 * (cada prueba usa la suya: no dependen unas de otras).
 */
export async function register(page: Page, organization = 'Tostadores E2E'): Promise<Account> {
  const account = {
    name: 'Ana Pruebas',
    email: `e2e-${Date.now()}-${Math.random().toString(36).slice(2, 7)}@loop7.test`,
    organization,
  }

  await page.goto('/registro')
  await page.getByLabel('Tu nombre').fill(account.name)
  await page.getByLabel(/Nombre de tu organización/).fill(organization)
  await page.getByLabel('Correo electrónico').fill(account.email)
  await page.getByLabel('Contraseña', { exact: true }).fill(E2E_PASSWORD)
  await page.getByLabel('Confirmar').fill(E2E_PASSWORD)
  await page.getByRole('checkbox', { name: /Acepto los términos/ }).check()
  await page.getByRole('button', { name: 'Crear cuenta' }).click()

  await expect(page).toHaveURL(/\/app/)
  await expect(page.getByRole('heading', { name: /Hola, Ana/ })).toBeVisible()

  return account
}

export async function login(page: Page, email: string): Promise<void> {
  await page.goto('/login')
  await page.getByLabel('Correo electrónico').fill(email)
  await page.getByLabel('Contraseña').fill(E2E_PASSWORD)
  await page.getByRole('button', { name: 'Entrar' }).click()
  await expect(page).toHaveURL(/\/app/)
}

export async function logout(page: Page): Promise<void> {
  await page.getByRole('button', { name: 'Menú de usuario' }).click()
  await page.getByRole('button', { name: 'Cerrar sesión' }).click()
  await expect(page).toHaveURL(/\/login/)
}

/** Aviso (toast) con el texto indicado. */
export async function expectToast(page: Page, text: string | RegExp): Promise<void> {
  await expect(page.getByText(text).first()).toBeVisible()
}
