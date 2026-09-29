import { expect, test } from '@playwright/test'
import {
  addFakeVariant,
  approve,
  connectFakeAccount,
  createBrand,
  createContent,
  expectToast,
  localDateTime,
  register,
  scheduleAt,
} from './support'

test.describe('Calendario', () => {
  test('semana y día: ver por horas, abrir un día, reprogramar arrastrando y filtrar', async ({ page }, testInfo) => {
    // Recorre el alta completa antes de llegar al calendario.
    test.setTimeout(180_000)
    await register(page, 'Calendario E2E')
    await createBrand(page, 'Café Semanal')
    await connectFakeAccount(page, 'Café Semanal')
    await createContent(page, 'Cata del viernes')
    await addFakeVariant(page)
    await approve(page)

    const day = new Date(Date.now() + 2 * 24 * 3600 * 1000)
    day.setHours(10, 0, 0, 0)
    await scheduleAt(page, day)
    const fecha = localDateTime(day).slice(0, 10)

    // Semana (desde la URL): la publicación está en la fila de las 10:00.
    await page.goto(`/app/calendar?vista=semana&fecha=${fecha}`)
    await expect(page.getByRole('tab', { name: 'Semana', selected: true })).toBeVisible()
    await expect(page.locator('[data-hour="10"]').getByRole('link', { name: /10:00\s*Cata del viernes/ })).toBeVisible()
    await page.screenshot({ path: testInfo.outputPath('calendario-semana.png') })

    // La cabecera de cada día abre su vista Día (y la URL lo refleja).
    await page.getByRole('button', { name: new RegExp(`^Ver el .*\\b${day.getDate()} de`) }).click()
    await expect(page).toHaveURL(new RegExp(`vista=dia&fecha=${fecha}`))
    await expect(page.getByRole('tab', { name: 'Día', selected: true })).toBeVisible()

    // Arrastrar a otra hora reprograma a esa hora (conservando los minutos). El arrastre
    // nativo no se emula de forma fiable en Chrome sin interfaz: se emiten los eventos
    // HTML5 (los mismos que maneja la app) sobre los elementos reales.
    const item = page.locator('[data-hour="10"]').getByRole('link', { name: /Cata del viernes/ })
    const target = page.locator('[data-hour="15"] > div').nth(1)
    const dataTransfer = await page.evaluateHandle(() => new DataTransfer())
    await item.dispatchEvent('dragstart', { dataTransfer })
    await target.dispatchEvent('dragenter', { dataTransfer })
    await target.dispatchEvent('dragover', { dataTransfer })
    await target.dispatchEvent('drop', { dataTransfer })
    await item.dispatchEvent('dragend', { dataTransfer })
    await expectToast(page, /Reprogramado para el/)
    await expect(page.locator('[data-hour="15"]').getByRole('link', { name: /15:00\s*Cata del viernes/ })).toBeVisible()
    await page.screenshot({ path: testInfo.outputPath('calendario-dia.png') })

    // Filtro por estado.
    await page.getByLabel('Estado').selectOption('published')
    await expect(page.getByRole('link', { name: /Cata del viernes/ })).toHaveCount(0)
    await page.getByRole('button', { name: 'Quitar filtros' }).click()
    await expect(page.getByRole('link', { name: /Cata del viernes/ })).toHaveCount(1)

    // Navegación por días y vuelta a la semana.
    await page.getByRole('button', { name: 'Día siguiente' }).click()
    await expect(page.getByRole('link', { name: /Cata del viernes/ })).toHaveCount(0)
    await page.getByRole('button', { name: 'Día anterior' }).click()
    await page.getByRole('tab', { name: 'Semana' }).click()
    await expect(page.locator('[data-hour="15"]').getByRole('link', { name: /15:00\s*Cata del viernes/ })).toBeVisible()

    // La URL manda: el enlace del menú, estando ya en el calendario, vuelve al mes actual.
    await page.getByRole('navigation', { name: 'Navegación principal' }).getByRole('link', { name: 'Calendario' }).click()
    await expect(page.getByRole('tab', { name: 'Mes', selected: true })).toBeVisible()
    await expect(page).toHaveURL(/vista=mes/)

    // En móvil la semana se desplaza dentro de su tarjeta: la página no se desborda.
    await page.getByRole('tab', { name: 'Semana' }).click()
    await page.setViewportSize({ width: 390, height: 844 })
    await expect(page.getByRole('tab', { name: 'Semana', selected: true })).toBeVisible()
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
    expect(overflow).toBeLessThanOrEqual(0)
    await page.screenshot({ path: testInfo.outputPath('calendario-semana-movil.png') })
  })
})
