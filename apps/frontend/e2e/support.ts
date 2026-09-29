import { execFileSync } from 'node:child_process'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { expect, type Page } from '@playwright/test'

/** Contraseña de las cuentas que crean las pruebas (sólo existe en la base E2E). */
export const E2E_PASSWORD = 'E2e-Loop7-2026'

const backend = resolve(dirname(fileURLToPath(import.meta.url)), '../../backend')

/**
 * Ejecuta un comando de Artisan contra el backend aislado (`--env=e2e`): para
 * preparar datos que la interfaz no puede crear (p. ej. historial ya medido).
 */
export function artisan(...args: string[]): void {
  execFileSync(process.env.PHP_BINARY ?? 'php', ['artisan', ...args, '--env=e2e'], {
    cwd: backend,
    env: { ...process.env, XDEBUG_MODE: 'off' },
    stdio: 'pipe',
  })
}

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

// --- Pasos del recorrido (marca, cuenta simulada, contenido, aprobación) ---

export async function createBrand(page: Page, name: string): Promise<void> {
  await page.goto('/app/brands')
  await page.getByRole('button', { name: 'Nueva marca' }).first().click()
  // Sin marcas, el estado vacío también tiene un botón «Crear marca»: se actúa en el diálogo.
  const dialog = page.getByRole('dialog', { name: 'Nueva marca' })
  await dialog.getByLabel('Nombre').fill(name)
  await dialog.getByRole('button', { name: 'Crear marca' }).click()
  await expectToast(page, 'Marca creada.')
}

export async function connectFakeAccount(page: Page, brand: string): Promise<void> {
  await page.getByRole('link', { name: new RegExp(brand) }).first().click()
  await page.getByRole('tab', { name: 'Redes' }).click()
  // El proveedor de prueba vuelve directo al callback OAuth con un código simulado.
  await page.getByRole('button', { name: /Conectar Proveedor de prueba/ }).click()
  await expect(page).toHaveURL(/social=connected|\/app\/brands\//)
  await expectToast(page, 'Cuenta conectada.')
  await expect(page.getByRole('button', { name: 'Desconectar' })).toBeVisible()
}

export async function createContent(page: Page, title: string): Promise<void> {
  await page.goto('/app/content')
  await page.getByRole('button', { name: 'Nuevo' }).click()
  const dialog = page.getByRole('dialog', { name: 'Nuevo contenido' })
  await dialog.getByLabel('Título').fill(title)
  await dialog.getByLabel('Texto base').fill('¡Nuevo tueste de temporada! Pásate a probarlo.')
  await dialog.getByRole('button', { name: 'Crear' }).click()
  await expect(page).toHaveURL(/\/app\/content\/[0-9A-Z]{26}/)
  await expect(page.getByRole('heading', { name: title })).toBeVisible()
}

export async function addFakeVariant(page: Page): Promise<void> {
  await page.getByLabel('Red social').selectOption({ label: 'Proveedor de prueba' })
  await page.getByRole('button', { name: 'Añadir red' }).click()
  await expectToast(page, 'Variante añadida.')
}

/** Con aprobaciones en el plan: enviar y aprobar; sin ellas: marcar como listo. */
export async function approve(page: Page): Promise<void> {
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

/** Fecha local «YYYY-MM-DDTHH:MM» para un <input type="datetime-local">. */
export function localDateTime(date: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

/** Programa el contenido abierto para la fecha indicada. */
export async function scheduleAt(page: Page, date: Date): Promise<void> {
  await page.getByLabel('Fecha y hora de publicación').fill(localDateTime(date))
  await page.getByRole('button', { name: 'Programar', exact: true }).click()
  await expectToast(page, 'Programado.')
}
