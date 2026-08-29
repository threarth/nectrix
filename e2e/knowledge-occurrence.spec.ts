// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, test, type Page } from '@playwright/test'

test('crea un Concept da selezione e lo conserva dopo save/reload', async ({ page }) => {
  await page.goto('/')
  await newDocument(page)
  await createConceptFrom(page, 'Backlog', 'Backlog')
  await page.getByRole('button', { name: 'Salva' }).click()
  await page.reload()
  await expect(page.locator('.nectrix-knowledge-occurrence')).toHaveText('Backlog')
})

test('crea una Entity con EntityType da selezione e la conserva dopo save/reload', async ({ page }) => {
  await page.goto('/')
  await newDocument(page)
  await createEntityFrom(page, 'Rocket Lab', 'Rocket Lab USA', 'Company')
  await page.getByRole('button', { name: 'Salva' }).click()
  await page.reload()
  await expect(page.locator('.nectrix-knowledge-occurrence')).toHaveText('Rocket Lab')
})

/** Drags the closing handle of the occurrence to the right, extending its range. */
async function dragEndHandle(page: Page): Promise<void> {
  const handle = page.locator('.nectrix-occurrence-handle-end')
  await expect(handle).toBeVisible()
  const box = await expect.poll(() => handle.boundingBox()).not.toBeNull().then(() => handle.boundingBox())
  if (box === null) throw new Error('Maniglia non visibile.')
  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2)
  await page.mouse.down()
  await page.mouse.move(box.x + 60, box.y + box.height / 2, { steps: 10 })
  await page.mouse.up()
}

/**
 * Document holding the occurrence "Backlog" followed by the unmarked tail " utile", saved.
 * The tail is typed after the mark, which is not inclusive, so it stays outside the occurrence.
 */
async function conceptWithTail(page: Page): Promise<void> {
  const editor = page.locator('.tiptap')
  await editor.click()
  await expect(editor).toBeFocused()
  await page.keyboard.type('Backlog')
  await expect(editor).toHaveText('Backlog')
  await editor.press('Control+A')
  await toolbarButton(page, 'Crea Concept').click()
  const dialog = page.getByRole('dialog', { name: 'Nuovo Concept' })
  await dialog.getByLabel('Nome del Concept').fill('Backlog')
  await dialog.getByRole('button', { name: 'Crea Concept' }).click()
  await expect(editor.locator('.nectrix-knowledge-occurrence')).toHaveText('Backlog')

  await editor.press('ArrowRight')
  await page.keyboard.type(' utile')
  await expect(editor).toHaveText('Backlog utile')
  await expect(editor.locator('.nectrix-knowledge-occurrence')).toHaveText('Backlog')

  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)
}

/** Creates a Document and waits until it is the one open, so what follows lands on it. */
async function newDocument(page: Page): Promise<void> {
  await page.getByRole('button', { name: 'Nuovo documento' }).click()
  await expect(page.locator('.save-status')).toHaveText('Revisione 0')
}

/** Toolbar command, distinguished from the same command offered on the selection. */
function toolbarButton(page: Page, name: string) {
  return page.getByRole('toolbar').getByRole('button', { name, exact: true })
}

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
  await toolbarButton(page, 'Crea Concept').click()
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
  await toolbarButton(page, 'Crea Entity').click()
  const dialog = page.getByRole('dialog', { name: 'Nuova Entity' })
  await dialog.getByLabel('Nome della Entity').fill(name)
  await dialog.getByLabel('Nome del nuovo EntityType').fill(entityType)
  await dialog.getByRole('button', { name: 'Crea Entity' }).click()
  await expect(editor.locator('.nectrix-knowledge-occurrence')).toHaveText(text)
}

test('INV-OCC-10: il copy/paste crea una seconda occurrence con ID nuovo e stesso Concept', async ({ page }) => {
  await page.goto('/')
  await newDocument(page)
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
  await newDocument(page)
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
  await newDocument(page)
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
  await newDocument(page)
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
  await newDocument(page)
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
  await newDocument(page)
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
  await newDocument(page)
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
  await newDocument(page)
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
  await newDocument(page)
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
  await newDocument(page)
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
  await newDocument(page)
  await createConceptFrom(page, 'Metodo scientifico', 'Metodo scientifico')
  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)

  await newDocument(page)
  const editor = page.locator('.tiptap')
  await editor.click()
  await page.keyboard.type('Qui parlo del metodo')
  await editor.press('Control+A')
  await toolbarButton(page, 'Associa esistente').click()

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
  await newDocument(page)
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
  await newDocument(page)
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

  await newDocument(page)
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

test('le maniglie correggono il confine dell’occurrence mantenendo lo stesso ID', async ({ page }) => {
  await page.goto('/')
  await newDocument(page)
  const editor = page.locator('.tiptap')
  await conceptWithTail(page)
  const [before] = await occurrenceIds(page)

  await editor.locator('.nectrix-knowledge-occurrence').click()
  await dragEndHandle(page)

  await expect(editor.locator('.nectrix-knowledge-occurrence')).toHaveText('Backlog utile')
  expect(await occurrenceIds(page)).toEqual([before])

  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)
  await page.reload()
  await expect(page.locator('.tiptap .nectrix-knowledge-occurrence')).toHaveText('Backlog utile')
  expect(await occurrenceIds(page)).toEqual([before])
})

test('il pannello mostra il confine corretto subito dopo il trascinamento, prima del salvataggio', async ({ page }) => {
  await page.goto('/')
  await newDocument(page)
  const editor = page.locator('.tiptap')
  await conceptWithTail(page)

  await editor.locator('.nectrix-knowledge-occurrence').click()
  await dragEndHandle(page)
  await expect(editor.locator('.nectrix-knowledge-occurrence')).toHaveText('Backlog utile')

  await page.getByRole('button', { name: 'Apri Concept' }).click()
  const occurrence = page.locator('.inspector-occurrences .occurrence-text').first()
  await expect(occurrence).toHaveText('Backlog utile')

  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)
  await expect(occurrence).toHaveText('Backlog utile')
})

test('la barra dei comandi compare sulla selezione e crea il Concept senza passare dalla toolbar', async ({ page }) => {
  await page.goto('/')
  await newDocument(page)
  const editor = page.locator('.tiptap')
  await editor.click()
  await expect(editor).toBeFocused()
  await page.keyboard.type('Il metodo scientifico')
  await expect(editor).toHaveText('Il metodo scientifico')

  await editor.locator('p').dblclick()
  const bar = page.getByLabel('Comandi sul testo selezionato')
  await expect(bar.getByRole('button', { name: 'Crea Concept' })).toBeVisible()
  await expect(bar.getByRole('button', { name: 'Crea Entity' })).toBeVisible()
  await expect(bar.getByRole('button', { name: 'Associa esistente' })).toBeVisible()

  await bar.getByRole('button', { name: 'Crea Concept' }).click()
  const dialog = page.getByRole('dialog', { name: 'Nuovo Concept' })
  await dialog.getByLabel('Nome del Concept').fill('Scienza')
  await dialog.getByRole('button', { name: 'Crea Concept' }).click()

  await expect(editor.locator('.nectrix-knowledge-occurrence')).toHaveCount(1)
  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)
  await page.reload()
  await expect(page.locator('.tiptap .nectrix-knowledge-occurrence')).toHaveCount(1)
})

/** Computed background of the first occurrence and of the element painted behind it. */
async function occurrenceColours(page: Page): Promise<{ background: string; behind: string | null }> {
  return page.locator('.tiptap .nectrix-knowledge-occurrence').first().evaluate((node) => {
    const parent = node.parentElement
    return {
      background: window.getComputedStyle(node).backgroundColor,
      behind: parent === null ? null : window.getComputedStyle(parent).backgroundColor,
    }
  })
}

/** Fixed canonical UUIDv7 values: the e2e database is rebuilt at every run. */
const LEGACY_OCCURRENCE = '01a04800-0000-7000-8000-00000000ce01'
const LEGACY_CONCEPT = '01a04800-0000-7000-8000-00000000c001'

test('un Concept resta visibile anche sopra una evidenziazione già salvata', async ({ page }) => {
  // Stato ereditato: prima che un Concept escludesse l'evidenziazione, i due mark potevano
  // convivere sullo stesso testo. Il documento va costruito dall'API perché la UI non lo permette.
  await page.goto('/')
  const created = await page.request.post('/api/documents', { data: { title: 'Documento ereditato' } })
  const documentId = (await created.json()).document.id
  const highlight = { type: 'highlight', attrs: { color: '#f6dd79' } }
  const occurrence = {
    type: 'knowledgeOccurrence',
    attrs: { occurrenceId: LEGACY_OCCURRENCE, knowledgeObjectId: LEGACY_CONCEPT, objectType: 'concept' },
  }
  const saved = await page.request.put(`/api/documents/${documentId}`, {
    data: {
      baseRevision: 0,
      title: 'Documento ereditato',
      documentJson: {
        type: 'doc',
        content: [{
          type: 'paragraph',
          content: [
            { type: 'text', marks: [highlight], text: 'Gli a' },
            { type: 'text', marks: [highlight, occurrence], text: 'rchetipi' },
          ],
        }],
      },
      occurrenceCreates: [{
        occurrenceId: LEGACY_OCCURRENCE,
        knowledgeObjectId: LEGACY_CONCEPT,
        objectType: 'concept',
        newObject: true,
        name: 'Archetipo',
      }],
    },
  })
  expect(saved.ok()).toBe(true)

  await page.goto('/')
  await expect(page.locator('.tiptap .nectrix-knowledge-occurrence')).toHaveText('rchetipi')

  const colours = await occurrenceColours(page)
  expect(colours.behind).toBe('rgb(246, 221, 121)')
  expect(colours.background).not.toBe('rgba(0, 0, 0, 0)')
  expect(colours.background).not.toBe(colours.behind)
})

test('creare un Concept toglie l’evidenziazione dal suo intervallo', async ({ page }) => {
  await page.goto('/')
  await newDocument(page)
  const editor = page.locator('.tiptap')
  await editor.click()
  await expect(editor).toBeFocused()
  await page.keyboard.type('Testo evidenziato')
  await expect(editor).toHaveText('Testo evidenziato')

  await editor.press('Control+A')
  await toolbarButton(page, 'Evidenzia').click()
  await expect(editor.locator('mark.nectrix-highlight')).toHaveCount(1)

  await editor.press('Control+A')
  await toolbarButton(page, 'Crea Concept').click()
  const dialog = page.getByRole('dialog', { name: 'Nuovo Concept' })
  await dialog.getByLabel('Nome del Concept').fill('Senza evidenziazione')
  await dialog.getByRole('button', { name: 'Crea Concept' }).click()

  await expect(editor.locator('.nectrix-knowledge-occurrence')).toHaveCount(1)
  await expect(editor.locator('mark.nectrix-highlight')).toHaveCount(0)
  await expect(toolbarButton(page, 'Evidenzia')).toBeDisabled()
})

test('evidenziare un paragrafo che contiene un Concept lascia il Concept intatto', async ({ page }) => {
  await page.goto('/')
  await newDocument(page)
  const editor = page.locator('.tiptap')
  await conceptWithTail(page)

  await editor.press('Control+A')
  await toolbarButton(page, 'Evidenzia').click()

  await expect(editor.locator('mark.nectrix-highlight')).toHaveText(' utile')
  await expect(editor.locator('.nectrix-knowledge-occurrence')).toHaveText('Backlog')
  await expect(editor.locator('.nectrix-knowledge-occurrence mark.nectrix-highlight')).toHaveCount(0)
  await expect(editor.locator('mark.nectrix-highlight .nectrix-knowledge-occurrence')).toHaveCount(0)
})

test('Concept ed Entity si distinguono a colpo d’occhio', async ({ page }) => {
  await page.goto('/')
  await newDocument(page)
  await createConceptFrom(page, 'Idea', 'Idea')
  const concept = await occurrenceColours(page)
  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)

  await newDocument(page)
  await createEntityFrom(page, 'Cosa', 'Cosa specifica', 'Oggetto')
  const entity = await occurrenceColours(page)

  expect(concept.background).not.toBe('rgba(0, 0, 0, 0)')
  expect(entity.background).not.toBe('rgba(0, 0, 0, 0)')
  expect(concept.background).not.toBe(entity.background)
})

test('CRUD: rinominare un Concept dall’inspector non tocca occurrence e alias', async ({ page }) => {
  await page.goto('/')
  await newDocument(page)
  await createConceptFrom(page, 'Bozza', 'Nome provvisorio')
  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)

  await page.locator('.tiptap .nectrix-knowledge-occurrence').click()
  await page.getByRole('button', { name: 'Apri Concept' }).click()
  const inspector = page.locator('.inspector')
  await page.getByLabel('Aggiungi alias').fill('Alias stabile')
  await inspector.getByRole('button', { name: 'Aggiungi alias' }).click()
  await expect(inspector.locator('.inspector-list li')).toContainText('Alias stabile')

  await inspector.getByRole('button', { name: 'Modifica' }).click()
  await inspector.getByLabel('Nome').fill('Nome definitivo')
  await inspector.getByLabel('Descrizione').fill('Spiegazione breve.')
  await inspector.getByRole('button', { name: 'Salva', exact: true }).click()

  await expect(inspector.locator('.inspector-name')).toHaveText('Nome definitivo')
  await expect(inspector.locator('.inspector-fields dd').first()).toHaveText('Spiegazione breve.')
  await expect(inspector.locator('.inspector-occurrences li')).toHaveCount(1)
  await expect(inspector.locator('.inspector-list li')).toContainText('Alias stabile')
  await expect(page.locator('.tiptap .nectrix-knowledge-occurrence')).toHaveText('Bozza')
})

test('FASE 8: i Context filtrano i documenti e ne derivano Concept ed Entity', async ({ page }) => {
  await page.goto('/')
  const panel = page.getByLabel('Contesti')

  await panel.getByRole('button', { name: 'Nuovo' }).click()
  let dialog = page.getByRole('dialog', { name: 'Nuovo contesto' })
  await dialog.getByLabel('Nome del contesto').fill('Università')
  await dialog.getByRole('button', { name: 'Nuovo' }).click()
  await expect(panel.getByRole('button', { name: 'Università' })).toBeVisible()

  await panel.getByRole('button', { name: 'Università' }).click()
  await panel.getByRole('button', { name: 'Nuovo' }).click()
  dialog = page.getByRole('dialog', { name: 'Nuovo contesto' })
  await expect(dialog).toContainText('Nasce dentro «Università»')
  await dialog.getByLabel('Nome del contesto').fill('Psicologia')
  await dialog.getByRole('button', { name: 'Nuovo' }).click()
  await expect(panel.getByRole('button', { name: 'Psicologia' })).toBeVisible()

  // Un documento con un Concept, assegnato al sotto-contesto.
  await newDocument(page)
  await createConceptFrom(page, 'Inconscio', 'Inconscio collettivo')
  await page.getByRole('textbox', { name: 'Titolo documento' }).fill('Appunti di psicologia')
  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)
  await page.getByLabel('Contesto del documento').selectOption({ label: 'Università / Psicologia' })

  // Il ramo vede il documento, il solo padre no.
  await panel.getByRole('button', { name: 'Università' }).click()
  await expect(page.locator('.sidebar nav')).toContainText('Appunti di psicologia')
  await expect(page.getByLabel('Concept ed Entity qui')).toContainText('Inconscio collettivo')

  await panel.getByRole('button', { name: 'Solo questo' }).click()
  await expect(page.locator('.sidebar nav')).not.toContainText('Appunti di psicologia')
  await expect(page.getByLabel('Concept ed Entity qui')).toContainText('Nessun Concept o Entity')

  await panel.getByRole('button', { name: 'Psicologia' }).click()
  await expect(page.locator('.sidebar nav')).toContainText('Appunti di psicologia')
  await expect(page.getByLabel('Concept ed Entity qui')).toContainText('Inconscio collettivo')
})

test('FASE 8: un Context con documenti non si elimina e non può diventare figlio di sé stesso', async ({ page }) => {
  await page.goto('/')
  const panel = page.getByLabel('Contesti')

  await panel.getByRole('button', { name: 'Nuovo' }).click()
  const dialog = page.getByRole('dialog', { name: 'Nuovo contesto' })
  await dialog.getByLabel('Nome del contesto').fill('Con documenti')
  await dialog.getByRole('button', { name: 'Nuovo' }).click()
  await panel.getByRole('button', { name: 'Con documenti' }).click()

  await newDocument(page)
  await page.getByRole('textbox', { name: 'Titolo documento' }).fill('Documento legato')
  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)
  await page.getByLabel('Contesto del documento').selectOption({ label: 'Con documenti' })

  await panel.getByRole('button', { name: 'Con documenti' }).click()

  // Il rifiuto viene spiegato accanto al comando, prima di tentarlo.
  await expect(panel.getByRole('button', { name: 'Elimina', exact: true })).toBeDisabled()
  await expect(panel).toContainText('Non eliminabile: ha un documento. Spostalo o riassegnalo prima.')
  await expect(panel.getByRole('button', { name: 'Con documenti' })).toBeVisible()

  // Tolto il documento dal contesto, l'eliminazione diventa possibile.
  await page.getByRole('button', { name: 'Documento legato' }).click()
  await page.getByLabel('Contesto del documento').selectOption({ label: 'Nessun contesto' })
  await panel.getByRole('button', { name: 'Con documenti' }).click()
  await expect(panel.getByRole('button', { name: 'Elimina', exact: true })).toBeEnabled()
  await panel.getByRole('button', { name: 'Elimina', exact: true }).click()
  await expect(panel.getByRole('button', { name: 'Con documenti' })).toHaveCount(0)
})

test('FASE 9: i Tag filtrano i documenti e restano separati dai Concept', async ({ page }) => {
  await page.goto('/')
  const tagPanel = page.getByLabel('Tag', { exact: true })

  await tagPanel.getByRole('button', { name: 'Nuovo' }).click()
  let dialog = page.getByRole('dialog', { name: 'Nuovo tag' })
  await dialog.getByLabel('Nome del tag').fill('#Da rileggere')
  await dialog.getByRole('button', { name: 'Nuovo' }).click()
  await expect(tagPanel.getByRole('button', { name: /Da rileggere/ })).toBeVisible()

  await newDocument(page)
  await createConceptFrom(page, 'Sincronicità', 'Sincronicità')
  await page.getByRole('textbox', { name: 'Titolo documento' }).fill('Documento con tag')
  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)
  await page.getByLabel('Aggiungi tag').selectOption({ label: 'Da rileggere' })
  await expect(page.locator('.document-tag')).toContainText('Da rileggere')

  await newDocument(page)
  await page.getByRole('textbox', { name: 'Titolo documento' }).fill('Documento senza tag')
  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)

  await tagPanel.getByRole('button', { name: /Da rileggere/ }).click()
  await expect(page.locator('.sidebar nav')).toContainText('Documento con tag')
  await expect(page.locator('.sidebar nav')).not.toContainText('Documento senza tag')
  await expect(page.getByLabel('Concept ed Entity qui').getByText('Sincronicità')).toBeVisible()

  await tagPanel.getByRole('button', { name: /Da rileggere/ }).click()
  await expect(page.locator('.sidebar nav')).toContainText('Documento senza tag')
})

test('FASE 9: un Tag assegnato non si elimina finché resta sui documenti', async ({ page }) => {
  await page.goto('/')
  const tagPanel = page.getByLabel('Tag', { exact: true })

  await tagPanel.getByRole('button', { name: 'Nuovo' }).click()
  const dialog = page.getByRole('dialog', { name: 'Nuovo tag' })
  await dialog.getByLabel('Nome del tag').fill('Assegnato')
  await dialog.getByRole('button', { name: 'Nuovo' }).click()

  await newDocument(page)
  await page.getByRole('textbox', { name: 'Titolo documento' }).fill('Documento del tag')
  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)
  await page.getByLabel('Aggiungi tag').selectOption({ label: 'Assegnato' })
  await expect(page.locator('.document-tag')).toContainText('Assegnato')

  await tagPanel.getByRole('button', { name: /Assegnato/ }).click()
  await expect(tagPanel.getByRole('button', { name: 'Elimina', exact: true })).toBeDisabled()
  await expect(tagPanel).toContainText('Non eliminabile: è su un documento')

  await page.locator('.document-tag button').click()
  await expect(page.locator('.document-tag')).toHaveCount(0)
  await expect(tagPanel.getByRole('button', { name: 'Elimina', exact: true })).toBeEnabled()
  await tagPanel.getByRole('button', { name: 'Elimina', exact: true }).click()
  await expect(tagPanel.getByRole('button', { name: /Assegnato/ })).toHaveCount(0)
})

test('FASE 10: la ricerca distingue testo, alias e identità', async ({ page }) => {
  await page.goto('/')
  await newDocument(page)
  await createConceptFrom(page, 'Individuazione', 'Individuazione')
  await page.getByRole('textbox', { name: 'Titolo documento' }).fill('Il processo psichico')
  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)

  // Un alias che non compare nel testo del documento.
  await page.locator('.tiptap .nectrix-knowledge-occurrence').click()
  await page.getByRole('button', { name: 'Apri Concept' }).click()
  await page.getByLabel('Aggiungi alias').fill('Individuazione junghiana')
  await page.locator('.inspector').getByRole('button', { name: 'Aggiungi alias' }).click()
  await expect(page.locator('.inspector-list li')).toContainText('Individuazione junghiana')

  const panel = page.getByLabel('Ricerca', { exact: true })
  await panel.getByRole('searchbox').fill('psichico')
  await panel.getByRole('button', { name: 'Cerca', exact: true }).click()
  await expect(panel.locator('.search-results li').first()).toContainText('Documento · testo')
  await expect(panel.locator('.search-results li').first()).toContainText('Il processo psichico')

  await panel.getByRole('searchbox').fill('junghiana')
  await panel.getByRole('button', { name: 'Cerca', exact: true }).click()
  const aliasResult = panel.locator('.search-results li').filter({ hasText: 'Concept · alias' })
  await expect(aliasResult).toHaveCount(1)
  await expect(aliasResult).toContainText('Individuazione')

  // Identità: i documenti si trovano dalle occurrence, non dalle parole.
  await aliasResult.getByRole('button', { name: 'Dove compare' }).click()
  await expect(panel.locator('.search-results li').first()).toContainText('Documento · occurrence')
  await expect(panel.locator('.search-results li').first()).toContainText('Il processo psichico')
})

test('FASE 10: dalla ricerca si aprono documento, Concept e contesto', async ({ page }) => {
  await page.goto('/')
  const contexts = page.getByLabel('Contesti')
  await contexts.getByRole('button', { name: 'Nuovo' }).click()
  const contextDialog = page.getByRole('dialog', { name: 'Nuovo contesto' })
  await contextDialog.getByLabel('Nome del contesto').fill('Ricerca contesto')
  await contextDialog.getByRole('button', { name: 'Nuovo' }).click()

  await newDocument(page)
  await createConceptFrom(page, 'Ombra', 'Ombra')
  await page.getByRole('textbox', { name: 'Titolo documento' }).fill('Documento della ricerca')
  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)

  const panel = page.getByLabel('Ricerca', { exact: true })
  await panel.getByRole('searchbox').fill('Ricerca')
  await panel.getByRole('button', { name: 'Cerca', exact: true }).click()

  await panel.locator('.search-results li').filter({ hasText: 'Contesto · nome' }).getByRole('button').first().click()
  await expect(contexts.getByRole('button', { name: 'Ricerca contesto' })).toHaveClass(/active/)

  await panel.getByRole('searchbox').fill('Ombra')
  await panel.getByRole('button', { name: 'Cerca', exact: true }).click()
  await panel.locator('.search-results li').filter({ hasText: 'Concept · nome' }).getByRole('button').first().click()
  await expect(page.locator('.inspector-name')).toHaveText('Ombra')
})

test('FASE 10.1: un Template si applica a una Entity e i valori restano tipizzati', async ({ page }) => {
  await page.goto('/')
  const templatePanel = page.getByLabel('Template', { exact: true })

  await templatePanel.getByRole('button', { name: 'Nuovo Template' }).click()
  const templateDialog = page.getByRole('dialog', { name: 'Nuovo Template' })
  await templateDialog.getByLabel('Nome del Template').fill('Scheda azienda')
  await templateDialog.getByRole('button', { name: 'Nuovo Template' }).click()
  await expect(templatePanel.getByRole('button', { name: /Scheda azienda/ })).toBeVisible()

  await templatePanel.getByRole('button', { name: /Scheda azienda/ }).click()
  await templatePanel.getByRole('button', { name: 'Aggiungi campo' }).click()
  let fieldDialog = page.getByRole('dialog', { name: 'Nuovo campo' })
  await fieldDialog.getByLabel('Nome del campo').fill('Dipendenti')
  await fieldDialog.getByLabel('Tipo').selectOption('number')
  await fieldDialog.getByRole('button', { name: 'Aggiungi campo' }).click()

  await templatePanel.getByRole('button', { name: 'Aggiungi campo' }).click()
  fieldDialog = page.getByRole('dialog', { name: 'Nuovo campo' })
  await fieldDialog.getByLabel('Nome del campo').fill('Mercato')
  await fieldDialog.getByLabel('Tipo').selectOption('enum')
  await fieldDialog.getByLabel('Opzioni separate da virgola').fill('Europa, Asia')
  await fieldDialog.getByRole('button', { name: 'Aggiungi campo' }).click()
  await expect(templatePanel.locator('.template-fields li')).toHaveCount(2)

  // Applica il Template a una Entity e scrive i valori.
  await newDocument(page)
  await createEntityFrom(page, 'Rocket Lab', 'Rocket Lab USA', 'Azienda')
  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)
  await page.locator('.tiptap .nectrix-knowledge-occurrence').click()
  await page.getByRole('button', { name: 'Apri Entity' }).click()

  const structured = page.getByLabel('Dati strutturati')
  await structured.getByLabel('Applica un Template').selectOption({ label: 'Scheda azienda' })
  await expect(structured.locator('.structured-block')).toHaveCount(1)

  await structured.getByLabel('Dipendenti').fill('1800')
  await structured.getByLabel('Dipendenti').blur()
  await structured.getByLabel('Mercato').selectOption('Europa')

  // I valori sono persistiti: sopravvivono a un ricaricamento completo.
  await page.reload()
  await page.locator('.tiptap .nectrix-knowledge-occurrence').click()
  await page.getByRole('button', { name: 'Apri Entity' }).click()
  const reopened = page.getByLabel('Dati strutturati')
  await expect(reopened.getByLabel('Dipendenti')).toHaveValue('1800')
  await expect(reopened.getByLabel('Mercato')).toHaveValue('Europa')

  // I dati strutturati non toccano il documento.
  await expect(page.locator('.tiptap')).toHaveText('Rocket Lab')
})

test('FASE 10.1.1: un riferimento mostra la destinazione senza copiarla nel documento', async ({ page }) => {
  await page.goto('/')
  await newDocument(page)
  await createEntityFrom(page, 'Rocket Lab', 'Rocket Lab riferimento', 'Azienda riferita')
  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)

  await newDocument(page)
  const editor = page.locator('.tiptap')
  await editor.click()
  await page.keyboard.type('Vedi anche ')
  await page.getByRole('toolbar').getByRole('button', { name: 'Riferimento' }).click()

  const dialog = page.getByRole('dialog', { name: 'Inserisci un riferimento' })
  await dialog.getByLabel('Cerca una Entity').fill('Rocket Lab riferimento')
  await dialog.getByRole('button', { name: 'Cerca', exact: true }).click()
  await expect(dialog.getByText('Rocket Lab riferimento')).toBeVisible()
  await dialog.getByRole('button', { name: 'Inserisci' }).click()

  const reference = editor.locator('.nectrix-reference')
  await expect(reference).toHaveCount(1)
  await expect(reference).toHaveAttribute('data-label', 'Rocket Lab riferimento')

  // Il contenuto salvato porta solo gli ID: il nome non compare nel documento.
  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)
  await page.reload()
  await expect(page.locator('.tiptap .nectrix-reference')).toHaveAttribute('data-label', 'Rocket Lab riferimento')
  await expect(page.locator('.tiptap')).toHaveText('Vedi anche ')

  const attributes = await page.locator('.tiptap .nectrix-reference').evaluate((node) =>
    Array.from(node.attributes).map((attribute) => attribute.name).sort())
  expect(attributes).toContain('data-reference-id')
  expect(attributes).toContain('data-entity-id')
  expect(attributes).not.toContain('data-name')
})

test('FASE 10.1.1: copiare un riferimento genera una nuova collocazione, stessa destinazione', async ({ page }) => {
  await page.goto('/')
  await newDocument(page)
  await createEntityFrom(page, 'Electron', 'Electron vettore', 'Vettore')
  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)

  await newDocument(page)
  const editor = page.locator('.tiptap')
  await editor.click()
  await page.getByRole('toolbar').getByRole('button', { name: 'Riferimento' }).click()
  const dialog = page.getByRole('dialog', { name: 'Inserisci un riferimento' })
  await dialog.getByLabel('Cerca una Entity').fill('Electron vettore')
  await dialog.getByRole('button', { name: 'Cerca', exact: true }).click()
  await expect(dialog.getByText('Electron vettore')).toBeVisible()
  await dialog.getByRole('button', { name: 'Inserisci' }).click()
  await expect(editor.locator('.nectrix-reference')).toHaveCount(1)

  const before = await editor.locator('.nectrix-reference').getAttribute('data-reference-id')
  await editor.press('Control+A')
  await page.keyboard.press('Control+C')
  await page.keyboard.press('ArrowRight')
  await page.keyboard.press('Control+V')

  await expect(editor.locator('.nectrix-reference')).toHaveCount(2)
  const ids = await editor.locator('.nectrix-reference').evaluateAll((nodes) =>
    nodes.map((node) => node.getAttribute('data-reference-id')))
  const destinations = await editor.locator('.nectrix-reference').evaluateAll((nodes) =>
    nodes.map((node) => node.getAttribute('data-entity-id')))
  expect(new Set(ids).size).toBe(2)
  expect(ids).toContain(before)
  expect(new Set(destinations).size).toBe(1)
})

test('FASE 10.2: la ricerca strutturata confronta i numeri come numeri', async ({ page }) => {
  await page.goto('/')
  const templatePanel = page.getByLabel('Template', { exact: true })

  await templatePanel.getByRole('button', { name: 'Nuovo Template' }).click()
  const templateDialog = page.getByRole('dialog', { name: 'Nuovo Template' })
  await templateDialog.getByLabel('Nome del Template').fill('Scheda numerica')
  await templateDialog.getByRole('button', { name: 'Nuovo Template' }).click()
  await templatePanel.getByRole('button', { name: /Scheda numerica/ }).click()
  await templatePanel.getByRole('button', { name: 'Aggiungi campo' }).click()
  const fieldDialog = page.getByRole('dialog', { name: 'Nuovo campo' })
  await fieldDialog.getByLabel('Nome del campo').fill('Dipendenti')
  await fieldDialog.getByLabel('Tipo').selectOption('number')
  await fieldDialog.getByRole('button', { name: 'Aggiungi campo' }).click()

  // Due Entity: una grande e una piccola, con valori che ingannerebbero un confronto testuale.
  for (const [entity, value] of [['Grande numerica', '1800'], ['Piccola numerica', '90']] as const) {
    await newDocument(page)
    await createEntityFrom(page, entity, entity, 'Azienda numerica')
    await page.getByRole('button', { name: 'Salva' }).click()
    await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)
    await page.locator('.tiptap .nectrix-knowledge-occurrence').click()
    await page.getByRole('button', { name: 'Apri Entity' }).click()
    const structured = page.getByLabel('Dati strutturati')
    await structured.getByLabel('Applica un Template').selectOption({ label: 'Scheda numerica' })
    await structured.getByLabel('Dipendenti').fill(value)
    await structured.getByLabel('Dipendenti').blur()
    await expect(structured.getByLabel('Dipendenti')).toHaveValue(value)
    await page.locator('.inspector-close').click()
  }

  const search = page.getByLabel('Cerca nei dati')
  await search.getByLabel('Campo').selectOption({ label: 'Dipendenti' })
  await search.getByLabel('Confronto').selectOption('gt')
  await search.getByLabel('Valore').fill('1000')
  await search.getByRole('button', { name: 'Cerca' }).click()

  await expect(page.getByLabel('Entity trovate').locator('li')).toHaveCount(1)
  await expect(page.getByLabel('Entity trovate')).toContainText('Grande numerica')
  await expect(page.getByLabel('Entity trovate')).toContainText('Dipendenti · maggiore di')

  await page.getByLabel('Entity trovate').getByRole('button').first().click()
  await expect(page.locator('.inspector-name')).toHaveText('Grande numerica')
})

test('FASE 11: una relazione si dichiara, ha una direzione e non nasce da sola', async ({ page }) => {
  await page.goto('/')
  await newDocument(page)
  await createConceptFrom(page, 'Individuazione', 'Individuazione junghiana')
  await page.getByRole('textbox', { name: 'Titolo documento' }).fill('Documento delle relazioni')
  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)

  // Una seconda Entity nello stesso documento: co-occorrenza, non relazione.
  await page.locator('.tiptap').click()
  await page.keyboard.press('Control+End')
  await page.keyboard.type(' e Jung')
  await page.keyboard.press('Shift+ArrowLeft')
  await page.keyboard.press('Shift+ArrowLeft')
  await page.keyboard.press('Shift+ArrowLeft')
  await page.keyboard.press('Shift+ArrowLeft')
  await page.getByLabel('Comandi sul testo selezionato').getByRole('button', { name: 'Crea Entity' }).click()
  const entityDialog = page.getByRole('dialog', { name: 'Nuova Entity' })
  await entityDialog.getByLabel('Nome della Entity').fill('Carl Gustav Jung')
  await entityDialog.getByLabel('Nome del nuovo EntityType').fill('Studioso')
  await entityDialog.getByRole('button', { name: 'Crea Entity' }).click()
  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)

  // Il Concept non ha relazioni malgrado la co-occorrenza.
  await page.locator('.tiptap .nectrix-knowledge-occurrence').first().click()
  await page.getByRole('button', { name: 'Apri Concept' }).click()
  const relations = page.getByLabel('Relazioni')
  await expect(relations).toContainText('Nessuna relazione dichiarata')

  await relations.getByRole('button', { name: 'Collega' }).click()
  const dialog = page.getByRole('dialog', { name: 'Nuova relazione' })
  await dialog.getByLabel('Predicato').fill('è studiato da')
  await dialog.getByLabel('Cerca la destinazione').fill('Carl Gustav Jung')
  await dialog.getByRole('button', { name: 'Cerca', exact: true }).click()
  await expect(dialog.getByText('Carl Gustav Jung')).toBeVisible()
  await dialog.getByRole('button', { name: 'Collega' }).click()

  await expect(relations.locator('li')).toHaveCount(1)
  await expect(relations).toContainText('è studiato da')
  await expect(relations).toContainText('Carl Gustav Jung')
  await expect(relations.locator('.relation-direction')).toHaveText('→')

  // Dall'altro capo la stessa relazione risulta entrante.
  await relations.getByRole('button', { name: /Carl Gustav Jung/ }).first().click()
  await expect(page.locator('.inspector-name')).toHaveText('Carl Gustav Jung')
  await expect(page.getByLabel('Relazioni').locator('.relation-direction')).toHaveText('←')
  await expect(page.getByLabel('Relazioni')).toContainText('Individuazione junghiana')
})

test('FASE 12: una relazione dichiara su quali dati si basa', async ({ page }) => {
  await page.goto('/')
  await newDocument(page)
  await createConceptFrom(page, 'Sincronicità', 'Sincronicità evidenza')
  await page.getByRole('textbox', { name: 'Titolo documento' }).fill('Fonte della relazione')
  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)

  await newDocument(page)
  await createConceptFrom(page, 'Causalità', 'Causalità evidenza')
  await page.getByRole('textbox', { name: 'Titolo documento' }).fill('Documento collegato')
  await page.getByRole('button', { name: 'Salva' }).click()
  await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)

  await page.locator('.tiptap .nectrix-knowledge-occurrence').click()
  await page.getByRole('button', { name: 'Apri Concept' }).click()
  const relations = page.getByLabel('Relazioni')
  await relations.getByRole('button', { name: 'Collega' }).click()
  const dialog = page.getByRole('dialog', { name: 'Nuova relazione' })
  await dialog.getByLabel('Predicato').fill('si oppone a')
  await dialog.getByLabel('Cerca la destinazione').fill('Sincronicità evidenza')
  await dialog.getByRole('button', { name: 'Cerca', exact: true }).click()
  await expect(dialog.getByText('Sincronicità evidenza')).toBeVisible()
  await dialog.getByRole('button', { name: 'Collega' }).click()
  await expect(relations.locator('li')).toHaveCount(1)

  await relations.getByRole('button', { name: 'Evidenze' }).click()
  const evidence = page.getByLabel('Su cosa si basa')
  await expect(evidence).toContainText('Nessuna evidenza dichiarata')

  await evidence.getByRole('button', { name: 'Aggiungi il documento aperto' }).click()
  await expect(evidence).toContainText('Documento collegato')
  await expect(evidence).toContainText('Documento')

  // Togliere l'evidenza non tocca il documento.
  await evidence.getByRole('button', { name: /Toglie l’evidenza/ }).click()
  await expect(evidence).toContainText('Nessuna evidenza dichiarata')
  await expect(page.locator('.sidebar nav')).toContainText('Documento collegato')
})

test('FASE 13: due Concept si confrontano affiancati sulla conoscenza registrata', async ({ page }) => {
  await page.goto('/')
  for (const [text, name] of [['Introversione', 'Introversione'], ['Estroversione', 'Estroversione']] as const) {
    await newDocument(page)
    await createConceptFrom(page, text, name)
    await page.getByRole('button', { name: 'Salva' }).click()
    await expect(page.getByText('Modifiche non salvate')).toHaveCount(0)
    await page.locator('.tiptap .nectrix-knowledge-occurrence').click()
    await page.getByRole('button', { name: 'Apri Concept' }).click()
    await expect(page.locator('.inspector-name')).toHaveText(name)

    if (name === 'Introversione') {
      await page.getByLabel('Aggiungi alias').fill('Rivolto all’interno')
      await page.locator('.inspector').getByRole('button', { name: 'Aggiungi alias' }).click()
      await expect(page.locator('.inspector-list li').first()).toContainText('Rivolto all’interno')
    }
    await page.locator('.inspector').getByRole('button', { name: 'Confronta' }).click()
  }

  const tray = page.getByLabel('Confronto', { exact: true })
  await expect(tray).toContainText('Introversione')
  await expect(tray).toContainText('Estroversione')
  await tray.getByRole('button', { name: 'Apri il confronto' }).click()

  const dialog = page.getByRole('dialog', { name: 'Confronto fra Concept' })
  await expect(dialog).toBeVisible()
  await expect(dialog.locator('thead th').nth(1)).toHaveText('Introversione')
  await expect(dialog.locator('thead th').nth(2)).toHaveText('Estroversione')

  const aliasRow = dialog.locator('tbody tr').filter({ hasText: 'Alias' })
  await expect(aliasRow.locator('td').first()).toContainText('Rivolto all’interno')
  await expect(aliasRow.locator('td').nth(1)).toContainText('—')

  const occurrenceRow = dialog.getByRole('row', { name: /^Occurrence/ })
  await expect(occurrenceRow).toContainText('derivato dalle occurrence')
})
