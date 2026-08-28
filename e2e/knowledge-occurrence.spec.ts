// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, test, type Page } from '@playwright/test'

test('crea un Concept da selezione e lo conserva dopo save/reload', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('button', { name: 'Nuovo documento' }).click()
  await createConceptFrom(page, 'Backlog', 'Backlog')
  await page.getByRole('button', { name: 'Salva' }).click()
  await page.reload()
  await expect(page.locator('.nectrix-knowledge-occurrence')).toHaveText('Backlog')
})

test('crea una Entity con EntityType da selezione e la conserva dopo save/reload', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('button', { name: 'Nuovo documento' }).click()
  await createEntityFrom(page, 'Rocket Lab', 'Rocket Lab USA', 'Company')
  await page.getByRole('button', { name: 'Salva' }).click()
  await page.reload()
  await expect(page.locator('.nectrix-knowledge-occurrence')).toHaveText('Rocket Lab')
})

/** Occurrence IDs currently rendered in the editor, in document order. */
async function occurrenceIds(page: Page): Promise<(string | null)[]> {
  return page.locator('.tiptap .nectrix-knowledge-occurrence').evaluateAll((nodes) =>
    nodes.map((node) => node.getAttribute('data-occurrence-id')))
}

async function createConceptFrom(page: Page, text: string, name: string): Promise<void> {
  const editor = page.locator('.tiptap')
  await editor.click()
  await expect(editor).toBeFocused()
  await page.keyboard.type(text)
  await expect(editor).toHaveText(text)
  await editor.press('Control+A')
  await page.getByRole('button', { name: 'Crea Concept' }).click()
  const dialog = page.getByRole('dialog', { name: 'Nuovo Concept' })
  await dialog.getByLabel('Nome del Concept').fill(name)
  await dialog.getByRole('button', { name: 'Crea Concept' }).click()
  await expect(editor.locator('.nectrix-knowledge-occurrence')).toHaveText(text)
}

/** Creates an Entity from the whole content of the editor. */
async function createEntityFrom(page: Page, text: string, name: string, entityType: string): Promise<void> {
  const editor = page.locator('.tiptap')
  await editor.click()
  await expect(editor).toBeFocused()
  await page.keyboard.type(text)
  await expect(editor).toHaveText(text)
  await editor.press('Control+A')
  await page.getByRole('button', { name: 'Crea Entity' }).click()
  const dialog = page.getByRole('dialog', { name: 'Nuova Entity' })
  await dialog.getByLabel('Nome della Entity').fill(name)
  await dialog.getByLabel('Nome del nuovo EntityType').fill(entityType)
  await dialog.getByRole('button', { name: 'Crea Entity' }).click()
  await expect(editor.locator('.nectrix-knowledge-occurrence')).toHaveText(text)
}

test('INV-OCC-10: il copy/paste crea una seconda occurrence con ID nuovo e stesso Concept', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('button', { name: 'Nuovo documento' }).click()
  await createConceptFrom(page, 'Backlog', 'Backlog')

  await page.locator('.tiptap').press('Control+A')
  await page.keyboard.press('Control+C')
  await page.keyboard.press('ArrowRight')
  await page.keyboard.type(' e ')
  await expect(page.locator('.tiptap')).toContainText('Backlog e')
  await page.keyboard.press('Control+V')

  await expect(page.locator('.tiptap .nectrix-knowledge-occurrence')).toHaveCount(2)
  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)
  await page.reload()
  await expect(page.locator('.tiptap .nectrix-knowledge-occurrence')).toHaveCount(2)

  const ids = await occurrenceIds(page)
  expect(ids).toHaveLength(2)
  expect(new Set(ids).size).toBe(2)
  const objectIds = await page.locator('.tiptap .nectrix-knowledge-occurrence').evaluateAll((nodes) =>
    nodes.map((node) => node.getAttribute('data-knowledge-object-id')))
  expect(new Set(objectIds).size).toBe(1)
})

test('INV-OCC-11: il cut/paste nello stesso documento conserva l’occurrenceId', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('button', { name: 'Nuovo documento' }).click()
  await createConceptFrom(page, 'Roadmap', 'Roadmap')

  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)
  const [before] = await occurrenceIds(page)

  await page.locator('.tiptap').press('Control+A')
  await page.keyboard.press('Control+X')
  await expect(page.locator('.tiptap .nectrix-knowledge-occurrence')).toHaveCount(0)
  await page.keyboard.press('Control+V')
  await expect(page.locator('.tiptap .nectrix-knowledge-occurrence')).toHaveCount(1)

  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)
  await page.reload()
  await expect(page.locator('.tiptap .nectrix-knowledge-occurrence')).toHaveCount(1)

  expect(await occurrenceIds(page)).toEqual([before])
})

test('INV-OCC-09: undo della creazione non lascia una creazione fantasma al salvataggio', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('button', { name: 'Nuovo documento' }).click()
  await createConceptFrom(page, 'Provvisorio', 'Provvisorio')

  await page.locator('.tiptap').press('Control+Z')
  await expect(page.locator('.tiptap .nectrix-knowledge-occurrence')).toHaveCount(0)

  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)
  await expect(page.getByRole('alert')).toHaveCount(0)
  await page.reload()
  await expect(page.locator('.tiptap')).toContainText('Provvisorio')

  expect(await occurrenceIds(page)).toEqual([])
})

test('INV-OCC-08 e INV-OCC-09: l’occurrence salvata torna attiva dopo cancellazione, salvataggio e undo', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('button', { name: 'Nuovo documento' }).click()
  await createConceptFrom(page, 'Riconciliazione', 'Riconciliazione')

  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)
  const [before] = await occurrenceIds(page)

  await page.locator('.tiptap').press('Control+A')
  await page.keyboard.press('Delete')
  await expect(page.locator('.tiptap .nectrix-knowledge-occurrence')).toHaveCount(0)
  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)

  await page.locator('.tiptap').press('Control+Z')
  await expect(page.locator('.tiptap .nectrix-knowledge-occurrence')).toHaveCount(1)
  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)
  await expect(page.getByRole('alert')).toHaveCount(0)

  await page.reload()
  await expect(page.locator('.tiptap .nectrix-knowledge-occurrence')).toHaveCount(1)
  expect(await occurrenceIds(page)).toEqual([before])
})

test('INV-OCC-09: un Concept creato, cancellato e ripristinato con undo viene creato al salvataggio', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('button', { name: 'Nuovo documento' }).click()
  await createConceptFrom(page, 'Mai salvato', 'Mai salvato')

  await page.locator('.tiptap').press('Control+A')
  await page.keyboard.press('Delete')
  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)

  await page.locator('.tiptap').press('Control+Z')
  await expect(page.locator('.tiptap .nectrix-knowledge-occurrence')).toHaveCount(1)
  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)
  await expect(page.getByRole('alert')).toHaveCount(0)

  await page.reload()
  await expect(page.locator('.tiptap .nectrix-knowledge-occurrence')).toHaveText('Mai salvato')
})

/** Opens the inspector of the occurrence currently in the document. */
async function openInspectorFromOccurrence(page: Page, label: string): Promise<void> {
  await page.locator('.tiptap .nectrix-knowledge-occurrence').first().click()
  await page.getByRole('button', { name: label }).click()
  await expect(page.locator('.inspector')).toBeVisible()
}

test('FASE 6: il popover di un Concept apre il Concept Inspector con le sue occurrence', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('button', { name: 'Nuovo documento' }).click()
  await createConceptFrom(page, 'Backlog', 'Backlog')
  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)

  await openInspectorFromOccurrence(page, 'Apri Concept')

  const inspector = page.locator('.inspector')
  await expect(inspector.locator('.inspector-kind')).toHaveText('Concept')
  await expect(inspector.locator('.inspector-name')).toHaveText('Backlog')
  await expect(inspector.locator('.inspector-status')).toContainText('attivo')
  await expect(inspector.locator('.occurrence-text')).toHaveText('Backlog')
})

test('FASE 6: archive e restore di un Concept cambiano solo lo stato', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('button', { name: 'Nuovo documento' }).click()
  await createConceptFrom(page, 'Archiviabile', 'Archiviabile')
  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)
  await openInspectorFromOccurrence(page, 'Apri Concept')

  const inspector = page.locator('.inspector')
  await inspector.getByRole('button', { name: 'Archivia', exact: true }).click()
  await expect(inspector.locator('.inspector-status')).toContainText('archiviato')
  await expect(inspector.locator('.occurrence-text')).toHaveText('Archiviabile')

  await inspector.getByRole('button', { name: 'Ripristina', exact: true }).click()
  await expect(inspector.locator('.inspector-status')).toContainText('attivo')
  await expect(page.locator('.tiptap .nectrix-knowledge-occurrence')).toHaveCount(1)
})

test('FASE 6: il popover di una Entity apre l’Entity Inspector con il suo EntityType', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('button', { name: 'Nuovo documento' }).click()
  await createEntityFrom(page, 'Rocket Lab', 'Rocket Lab USA', 'Azienda')
  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)

  await openInspectorFromOccurrence(page, 'Apri Entity')

  const inspector = page.locator('.inspector')
  await expect(inspector.locator('.inspector-kind')).toHaveText('Entity')
  await expect(inspector.locator('.inspector-name')).toHaveText('Rocket Lab USA')
  await expect(inspector.locator('.inspector-fields dd').first()).toContainText('Azienda')

  await inspector.getByRole('button', { name: 'Archivia EntityType' }).click()
  await expect(inspector.locator('.inspector-badge')).toHaveText('archiviato')
  await expect(inspector.locator('.inspector-name')).toHaveText('Rocket Lab USA')
  await inspector.getByRole('button', { name: 'Ripristina EntityType' }).click()
  await expect(inspector.locator('.inspector-badge')).toHaveCount(0)
})

test('FASE 6.1: archiviare un Document lo rende in sola lettura e lo toglie dagli attivi', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('button', { name: 'Nuovo documento' }).click()
  await createConceptFrom(page, 'Archiviato', 'Archiviato')
  const title = 'Documento archiviato'
  await page.getByRole('textbox', { name: 'Titolo documento' }).fill(title)
  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)
  await expect(page.locator('.sidebar nav')).toContainText(title)

  await page.getByRole('button', { name: 'Archivia', exact: true }).click()
  await expect(page.getByText('Sola lettura')).toBeVisible()
  await expect(page.getByRole('toolbar')).toHaveCount(0)
  await expect(page.getByRole('button', { name: 'Salva' })).toHaveCount(0)
  await expect(page.locator('.tiptap')).toHaveAttribute('contenteditable', 'false')
  await expect(page.locator('.tiptap .nectrix-knowledge-occurrence')).toHaveCount(1)

  await expect(page.locator('.sidebar nav')).not.toContainText(title)
  await page.getByRole('button', { name: 'Archiviati' }).click()
  await expect(page.locator('.sidebar nav')).toContainText(title)

  await page.getByRole('button', { name: 'Ripristina', exact: true }).click()
  await expect(page.getByRole('toolbar')).toHaveCount(1)
  await expect(page.locator('.tiptap')).toHaveAttribute('contenteditable', 'true')
})

test('FASE 6.1: il cestino è una vista di recupero e non elimina nulla', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('button', { name: 'Nuovo documento' }).click()
  await createConceptFrom(page, 'Cestinato', 'Cestinato')
  const title = 'Documento nel cestino'
  await page.getByRole('textbox', { name: 'Titolo documento' }).fill(title)
  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)

  await page.getByRole('button', { name: 'Cestina', exact: true }).click()
  await expect(page.getByText('Sola lettura')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Archivia', exact: true })).toHaveCount(0)

  await page.getByRole('button', { name: 'Cestino' }).click()
  await expect(page.locator('.sidebar nav')).toContainText(title)
  await page.getByRole('button', { name: 'Attivi' }).click()
  await expect(page.locator('.sidebar nav')).not.toContainText(title)

  await page.getByRole('button', { name: 'Ripristina', exact: true }).click()
  await expect(page.locator('.tiptap .nectrix-knowledge-occurrence')).toHaveText('Cestinato')
  await expect(page.getByRole('button', { name: 'Salva' })).toBeVisible()
})

test('la dialog di associazione cerca e collega un Concept esistente a un altro Document', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('button', { name: 'Nuovo documento' }).click()
  await createConceptFrom(page, 'Metodo scientifico', 'Metodo scientifico')
  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)

  await page.getByRole('button', { name: 'Nuovo documento' }).click()
  const editor = page.locator('.tiptap')
  await editor.click()
  await page.keyboard.type('Qui parlo del metodo')
  await editor.press('Control+A')
  await page.getByRole('button', { name: 'Associa esistente' }).click()

  const dialog = page.getByRole('dialog', { name: 'Associa un Concept o una Entity esistenti' })
  await dialog.getByLabel('Cerca').fill('Metodo')
  await dialog.getByRole('button', { name: 'Cerca', exact: true }).click()
  await expect(dialog.getByText('Metodo scientifico')).toBeVisible()
  await dialog.getByRole('button', { name: 'Associa', exact: true }).click()

  await expect(editor.locator('.nectrix-knowledge-occurrence')).toHaveText('Qui parlo del metodo')
  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)

  await page.locator('.tiptap .nectrix-knowledge-occurrence').click()
  await page.getByRole('button', { name: 'Apri Concept' }).click()
  await expect(page.locator('.inspector-name')).toHaveText('Metodo scientifico')
  await expect(page.locator('.inspector-occurrences li')).toHaveCount(2)
})

test('FASE 7: gli alias di un Concept si aggiungono e si rimuovono senza toccare le occurrence', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('button', { name: 'Nuovo documento' }).click()
  await createConceptFrom(page, 'Metodo', 'Metodo scientifico')
  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)

  await page.locator('.tiptap .nectrix-knowledge-occurrence').click()
  await page.getByRole('button', { name: 'Apri Concept' }).click()
  const inspector = page.locator('.inspector')

  await page.getByLabel('Aggiungi alias').fill('Metodo sperimentale')
  await inspector.getByRole('button', { name: 'Aggiungi alias' }).click()
  await expect(inspector.locator('.inspector-list li')).toContainText('Metodo sperimentale')
  await expect(inspector.locator('.inspector-occurrences li')).toHaveCount(1)

  await inspector.getByRole('button', { name: /Rimuove l’alias/ }).click()
  await expect(inspector.locator('.inspector-list li')).toHaveCount(0)
  await expect(inspector.locator('.inspector-occurrences li')).toHaveCount(1)
})

test('FASE 7: un identificatore duplicato su un’altra Entity produce un candidato, non una fusione', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('button', { name: 'Nuovo documento' }).click()
  await createEntityFrom(page, 'Prima', 'Prima società', 'Azienda')
  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)
  await page.locator('.tiptap .nectrix-knowledge-occurrence').click()
  await page.getByRole('button', { name: 'Apri Entity' }).click()

  const inspector = page.locator('.inspector')
  await inspector.getByLabel('Scheme').fill('lei')
  await inspector.getByLabel('Valore').fill('5493001KJTIIGC8Y1R12')
  await inspector.getByRole('button', { name: 'Aggiungi identificatore' }).click()
  await expect(inspector.locator('.inspector-list li').first()).toContainText('lei')
  await expect(inspector.locator('.inspector-duplicates')).toHaveCount(0)

  await page.getByRole('button', { name: 'Nuovo documento' }).click()
  await createEntityFrom(page, 'Seconda', 'Seconda società', 'Azienda')
  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)
  await page.locator('.tiptap .nectrix-knowledge-occurrence').click()
  await page.getByRole('button', { name: 'Apri Entity' }).click()

  await inspector.getByLabel('Scheme').fill('lei')
  await inspector.getByLabel('Valore').fill('5493001kjtiigc8y1r12')
  await inspector.getByRole('button', { name: 'Aggiungi identificatore' }).click()

  await expect(inspector.locator('.inspector-duplicates')).toContainText('Prima società')
  await expect(inspector.locator('.inspector-name')).toHaveText('Seconda società')
})
