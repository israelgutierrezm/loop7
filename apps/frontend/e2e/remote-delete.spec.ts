import { expect, test } from '@playwright/test'
import { addFakeVariant, approve, connectFakeAccount, createBrand, createContent, expectToast, register } from './support'

test.describe('Borrar de las redes', () => {
  test('borrar una publicación y retirar el resto de las redes', async ({ page }, testInfo) => {
    test.setTimeout(120_000)
    await register(page, 'Retirada E2E')
    await createBrand(page, 'Café Efímero')
    await connectFakeAccount(page, 'Café Efímero')
    await createContent(page, 'Oferta relámpago')
    await addFakeVariant(page)
    await approve(page)

    // La cuenta simulada tiene dos destinos (Página y Perfil): dos publicaciones.
    await page.getByRole('button', { name: /Publicar ahora/ }).click()
    await page.getByRole('dialog').getByRole('button', { name: /Publicar/ }).click()
    await expect(page.getByText('Publicado', { exact: true }).first()).toBeVisible()
    await expect(page.getByRole('button', { name: /^Borrar de .* la publicación de/ })).toHaveCount(2)

    // Borrar sólo la de la Página: el contenido sigue publicado en el Perfil.
    await page.getByRole('button', { name: /^Borrar de .* la publicación de Página Demo/ }).click()
    await page.getByRole('dialog').getByRole('button', { name: 'Borrar' }).click()
    await expectToast(page, 'Publicación borrada de la red.')
    await expect(page.getByText('Borrado de la red', { exact: true })).toHaveCount(1)
    await expect(page.getByRole('button', { name: /^Borrar de .* la publicación de/ })).toHaveCount(1)
    const variant = page.locator('article').filter({ hasText: 'Borrado de la red' })
    await variant.scrollIntoViewIfNeeded()
    await variant.screenshot({ path: testInfo.outputPath('una-borrada.png') })

    // Retirar el resto: el contenido queda «Retirado».
    await page.getByRole('button', { name: 'Retirar de las redes' }).click()
    await page.getByRole('dialog').getByRole('button', { name: 'Retirar' }).click()
    await expectToast(page, 'Retirado de las redes.')
    await expect(page.getByText('Borrado de la red', { exact: true })).toHaveCount(2)
    await expect(page.getByText('Retirado', { exact: true }).first()).toBeVisible()
    await expect(page.getByRole('button', { name: 'Retirar de las redes' })).toHaveCount(0)
    await page.screenshot({ path: testInfo.outputPath('retirado.png') })
    await variant.screenshot({ path: testInfo.outputPath('retirado-variante.png') })
  })

  test('eliminar un contenido publicado borrándolo también de las redes', async ({ page }, testInfo) => {
    test.setTimeout(120_000)
    await register(page, 'Limpieza E2E')
    await createBrand(page, 'Café Pasajero')
    await connectFakeAccount(page, 'Café Pasajero')
    await createContent(page, 'Promo caducada')
    await addFakeVariant(page)
    await approve(page)
    await page.getByRole('button', { name: /Publicar ahora/ }).click()
    await page.getByRole('dialog').getByRole('button', { name: /Publicar/ }).click()
    await expect(page.getByText('Publicado', { exact: true }).first()).toBeVisible()

    // El diálogo avisa de que lo publicado sigue en las redes y ofrece borrarlo también.
    await page.getByRole('button', { name: 'Eliminar' }).click()
    const dialog = page.getByRole('dialog', { name: 'Eliminar contenido' })
    await expect(dialog).toContainText('sigue en las redes')
    await dialog.getByRole('checkbox', { name: 'Borrar también las 2 publicaciones de sus redes' }).check()
    await dialog.screenshot({ path: testInfo.outputPath('eliminar-con-redes.png') })
    await dialog.getByRole('button', { name: 'Eliminar' }).click()

    await expectToast(page, 'Contenido eliminado y borrado de las redes.')
    await expect(page).toHaveURL(/\/app\/content$/)
    await expect(page.getByRole('link', { name: /Promo caducada/ })).toHaveCount(0)
  })
})
