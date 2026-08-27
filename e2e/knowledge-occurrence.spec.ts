// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, test } from '@playwright/test'

test('crea un Concept da selezione e lo conserva dopo save/reload', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('button', { name: 'Nuovo documento' }).click()
  const editor = page.locator('.tiptap')
  await editor.click()
  await page.keyboard.type('Backlog')
  await page.keyboard.press('Control+A')
  page.once('dialog', (dialog) => dialog.accept('Backlog'))
  await page.getByRole('button', { name: 'Crea Concept' }).click()
  await expect(editor.locator('.nectrix-knowledge-occurrence')).toHaveText('Backlog')
  await page.getByRole('button', { name: 'Salva' }).click()
  await page.reload()
  await expect(page.locator('.nectrix-knowledge-occurrence')).toHaveText('Backlog')
})

test('crea una Entity con EntityType da selezione e la conserva dopo save/reload', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('button', { name: 'Nuovo documento' }).click()
  const editor = page.locator('.tiptap')
  await editor.click()
  await page.keyboard.type('Rocket Lab')
  await page.keyboard.press('Control+A')
  let promptCount = 0
  page.on('dialog', (dialog) => {
    promptCount += 1
    void dialog.accept(promptCount === 1 ? 'Rocket Lab USA' : 'Company')
  })
  await page.getByRole('button', { name: 'Crea Entity' }).click()
  await expect(editor.locator('.nectrix-knowledge-occurrence')).toHaveText('Rocket Lab')
  await page.getByRole('button', { name: 'Salva' }).click()
  await page.reload()
  await expect(page.locator('.nectrix-knowledge-occurrence')).toHaveText('Rocket Lab')
})
