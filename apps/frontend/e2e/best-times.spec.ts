import { expect, test } from '@playwright/test'
import {
  addFakeVariant,
  approve,
  artisan,
  connectFakeAccount,
  createBrand,
  createContent,
  expectToast,
  localDateTime,
  register,
} from './support'

test.describe('Mejores horarios', () => {
  test('con historial medido: mapa de calor, horas destacadas en el calendario y sugerencias al programar', async ({ page }, testInfo) => {
    test.setTimeout(180_000)
    await register(page, 'Horarios E2E')
    await createBrand(page, 'Café Puntual')
    await connectFakeAccount(page, 'Café Puntual')
    const brandId = /\/app\/brands\/([0-9A-Z]{26})/.exec(page.url())?.[1]
    expect(brandId).toBeTruthy()

    // Con métricas de la cuenta pero sin publicaciones medidas: se indica cuánto falta.
    artisan('analytics:demo', brandId as string)
    await page.goto('/app/analytics')
    const card = page.getByRole('region', { name: 'Mejores horarios para publicar' })
    await expect(card.getByText(/Aún no hay datos suficientes/)).toBeVisible()
    await expect(card.getByRole('progressbar', { name: 'Publicaciones medidas' })).toHaveAttribute('aria-valuenow', '0')

    // 60 días de publicaciones ya medidas en la cuenta simulada: los martes y jueves a
    // las 10:00 y los miércoles a las 19:00 (hora de la marca) rinden más que lo habitual.
    artisan('analytics:demo', brandId as string, '--history')

    // Analítica: mapa de calor y franjas recomendadas.
    await page.reload()
    await expect(card.getByRole('table', { name: /Rendimiento de cada día y hora/ })).toBeVisible()
    const recommended = card.getByRole('list', { name: 'Recomendados' }).getByRole('listitem')
    await expect(recommended).toHaveCount(3)
    await expect(recommended.first()).toContainText('Martes a las 10:00')
    await card.scrollIntoViewIfNeeded()
    await card.screenshot({ path: testInfo.outputPath('mejores-horarios-analitica.png') })

    // Calendario de la semana próxima: esas horas aparecen destacadas.
    const nextWeek = new Date(Date.now() + 7 * 24 * 3600 * 1000)
    await page.goto(`/app/calendar?vista=semana&fecha=${localDateTime(nextWeek).slice(0, 10)}`)
    await expect(page.locator('[data-hour="10"] [data-best]')).toHaveCount(2)
    await expect(page.locator('[data-hour="19"] [data-best]')).toHaveCount(1)
    await expect(page.locator('[data-hour="10"] [data-best]').first()).toContainText('★')
    await page.locator('[data-hour="10"]').scrollIntoViewIfNeeded()
    await page.screenshot({ path: testInfo.outputPath('mejores-horarios-calendario.png') })
    // Se pueden ocultar.
    await page.getByRole('checkbox', { name: 'Mejores horarios' }).uncheck()
    await expect(page.locator('[data-best]')).toHaveCount(0)
    await page.getByRole('checkbox', { name: 'Mejores horarios' }).check()
    await expect(page.locator('[data-best]')).toHaveCount(3)

    // Al programar, las próximas fechas recomendadas rellenan el campo con un clic.
    await createContent(page, 'Tueste de la semana')
    await addFakeVariant(page)
    await approve(page)
    const suggestions = page.getByRole('group', { name: 'Mejores horarios para programar' }).getByRole('button')
    await expect(suggestions.first()).toBeVisible()
    await suggestions.first().click()
    await expect(page.getByLabel('Fecha y hora de publicación')).toHaveValue(/T(10|19):00$/)
    await page.screenshot({ path: testInfo.outputPath('mejores-horarios-programar.png') })
    await page.getByRole('button', { name: 'Programar', exact: true }).click()
    await expectToast(page, 'Programado.')
  })
})
