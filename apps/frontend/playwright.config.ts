import { defineConfig, devices } from '@playwright/test'

/**
 * Pruebas E2E en navegador (docs/15): recorren la app real contra un backend
 * aislado (SQLite propio, puerto 8002) y un Vite propio (puerto 5174), así que
 * pueden correr con el entorno de desarrollo encendido.
 *
 * En local usan el Chrome instalado (E2E_BROWSER_CHANNEL=msedge para Edge);
 * en CI, el Chromium de Playwright (`npx playwright install chromium`).
 */
const BACKEND_PORT = Number(process.env.E2E_BACKEND_PORT ?? 8002)
const FRONTEND_PORT = Number(process.env.E2E_FRONTEND_PORT ?? 5174)
const FRONTEND_URL = `http://localhost:${FRONTEND_PORT}`
const channel = process.env.CI ? undefined : (process.env.E2E_BROWSER_CHANNEL ?? 'chrome')

export default defineConfig({
  testDir: './e2e',
  // Comparten una base de datos: en serie y sin reintentos que oculten fallos.
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  timeout: 90_000,
  expect: { timeout: 15_000 },
  forbidOnly: !!process.env.CI,
  reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : [['list']],
  use: {
    baseURL: FRONTEND_URL,
    locale: 'es-ES',
    timezoneId: 'America/Mexico_City',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'], channel } }],
  webServer: [
    {
      command: 'node e2e/start-backend.mjs',
      url: `http://127.0.0.1:${BACKEND_PORT}/up`,
      env: { E2E_BACKEND_PORT: String(BACKEND_PORT), E2E_FRONTEND_URL: FRONTEND_URL },
      reuseExistingServer: false,
      timeout: 180_000,
      stdout: 'ignore',
      stderr: 'pipe',
    },
    {
      command: `npx vite --port ${FRONTEND_PORT} --strictPort`,
      url: FRONTEND_URL,
      env: { VITE_API_PROXY_TARGET: `http://127.0.0.1:${BACKEND_PORT}` },
      reuseExistingServer: false,
      timeout: 120_000,
    },
  ],
})
