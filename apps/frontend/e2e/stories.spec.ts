import { expect, test } from '@playwright/test'
import { addFakeVariant, approve, connectFakeAccount, createBrand, createContent, expectToast, register } from './support'

/** PNG de 1×1 válido (el backend comprueba el tipo real del archivo). */
const PNG = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
  'base64',
)

test.describe('Historias', () => {
  test('una historia lleva una imagen sin texto y se publica como historia', async ({ page }, testInfo) => {
    test.setTimeout(120_000)
    await register(page, 'Historias E2E')
    await createBrand(page, 'Café Efímero')
    await connectFakeAccount(page, 'Café Efímero')
    await createContent(page, 'Historia del lunes', 'Historia')

    await expect(page.getByRole('heading', { name: 'Historia por red' })).toBeVisible()
    await addFakeVariant(page)
    // Sin archivo aún: la historia lo avisa y no ofrece editar texto.
    await expect(page.getByText('Una historia lleva una sola imagen o un video.')).toBeVisible()
    await expect(page.getByText('Historia: se publica la imagen o el video, sin texto.')).toBeVisible()
    await expect(page.getByRole('button', { name: 'Editar texto' })).toHaveCount(0)

    // Subir la imagen desde el selector de medios.
    await page.getByRole('button', { name: 'Imagen o video' }).click()
    const picker = page.getByRole('dialog', { name: 'Imágenes y videos' })
    await picker.locator('input[type="file"]').setInputFiles({ name: 'historia.png', mimeType: 'image/png', buffer: PNG })
    await expectToast(page, 'Archivo subido.')
    await picker.getByRole('button', { name: 'Usar selección' }).click()
    await expectToast(page, 'Multimedia actualizada.')
    await expect(page.getByText('Una historia lleva una sola imagen o un video.')).toHaveCount(0)
    await page.screenshot({ path: testInfo.outputPath('historia-lista.png') })

    await approve(page)
    await page.getByRole('button', { name: /Publicar ahora/ }).click()
    await page.getByRole('dialog').getByRole('button', { name: /Publicar/ }).click()
    await expect(page.getByText('Publicado', { exact: true }).first()).toBeVisible()
    // El enlace lleva a la historia publicada.
    await expect(page.getByRole('link', { name: /Ver publicación/ }).first()).toHaveAttribute('href', /\/stories\//)
  })
})
