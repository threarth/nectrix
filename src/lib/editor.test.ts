// SPDX-License-Identifier: AGPL-3.0-or-later

import { Editor, type JSONContent } from '@tiptap/core'
import type { Slice } from '@tiptap/pm/model'
import { afterEach, describe, expect, test } from 'vitest'
import {
  editorExtensions,
  moveOccurrenceBoundary,
  occurrenceClipboardExtension,
  occurrenceFreeRanges,
  occurrenceRangeAt,
} from './editor'
import {
  collectOccurrences,
  CUT_CLIPBOARD_FORMAT,
  sliceFingerprint,
  type OccurrenceAttributes,
} from './occurrences'

const editors: Editor[] = []

function createEditor(content: JSONContent | string): Editor {
  const element = document.createElement('div')
  document.body.append(element)
  const editor = new Editor({ element, extensions: editorExtensions, content })
  editors.push(editor)
  return editor
}

afterEach(() => {
  for (const editor of editors.splice(0)) editor.destroy()
  document.body.replaceChildren()
})

const richDocument: JSONContent = {
  type: 'doc',
  content: [
    {
      type: 'heading',
      attrs: { level: 2 },
      content: [
        { type: 'text', text: 'Titolo ' },
        { type: 'text', marks: [{ type: 'bold' }, { type: 'underline' }], text: 'forte' },
      ],
    },
    {
      type: 'paragraph',
      content: [{ type: 'text', marks: [{ type: 'italic' }], text: 'Corpo normale' }],
    },
    {
      type: 'orderedList',
      attrs: { start: 3, type: null },
      content: [
        {
          type: 'listItem',
          content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Primo elemento' }] }],
        },
      ],
    },
    {
      type: 'bulletList',
      content: [
        {
          type: 'listItem',
          content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Secondo elemento' }] }],
        },
      ],
    },
    {
      type: 'blockquote',
      content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Citazione' }] }],
    },
  ],
}

function toggleHighlight(editor: Editor, from: number, to: number): void {
  expect(editor.chain().setTextSelection({ from, to }).toggleMark('highlight').run()).toBe(true)
}

function markNamesForText(editor: Editor, text: string): string[] {
  const content = editor.getJSON().content?.[0]?.content ?? []
  const node = content.find((item) => JSON.stringify(item).includes(text)) as
    | { marks?: Array<{ type: string }> }
    | undefined
  return node?.marks?.map((mark) => mark.type) ?? []
}

describe('schema editoriale fino alla FASE 2', () => {
  test('espone solo i nodi e mark persistenti in allowlist', () => {
    const editor = createEditor(richDocument)
    expect(Object.keys(editor.schema.nodes).sort()).toEqual(
      [
        'blockquote', 'bulletList', 'doc', 'entityReference', 'heading', 'listItem',
        'orderedList', 'paragraph', 'semanticBlockReference', 'text',
      ].sort(),
    )
    expect(Object.keys(editor.schema.marks).sort()).toEqual(
      ['bold', 'contextOccurrence', 'highlight', 'italic', 'knowledgeOccurrence', 'underline'],
    )
  })

  test('preserva semanticamente heading, liste, blockquote e formattazione inline al reload', () => {
    const first = createEditor(richDocument)
    const saved = first.getJSON()
    const reloaded = createEditor(saved)
    expect(reloaded.getJSON()).toEqual(richDocument)
  })

  test('history annulla e ripete una modifica mantenendo il JSON', () => {
    const editor = createEditor({ type: 'doc', content: [{ type: 'paragraph' }] })
    editor.commands.insertContent('testo')
    const changed = editor.getJSON()
    expect(changed).not.toEqual({ type: 'doc', content: [{ type: 'paragraph' }] })
    expect(editor.commands.undo()).toBe(true)
    expect(editor.getJSON()).toEqual({ type: 'doc', content: [{ type: 'paragraph' }] })
    expect(editor.commands.redo()).toBe(true)
    expect(editor.getJSON()).toEqual(changed)
  })

  test('serializza e ricarica highlight con colore della palette', () => {
    const editor = createEditor({
      type: 'doc',
      content: [{ type: 'paragraph', content: [{ type: 'text', text: 'testo evidenziato' }] }],
    })
    toggleHighlight(editor, 7, 18)

    const saved = editor.getJSON()
    expect(saved).toEqual({
      type: 'doc',
      content: [{
        type: 'paragraph',
        content: [
          { type: 'text', text: 'testo ' },
          { type: 'text', marks: [{ type: 'highlight', attrs: { color: '#f6dd79' } }], text: 'evidenziato' },
        ],
      }],
    })
    expect(createEditor(saved).getJSON()).toEqual(saved)
  })

  test('serializza il mark comune KnowledgeOccurrence con entrambi i discriminator', () => {
    const occurrenceId = '0198be7a-1732-7c6d-94a5-7ce8e2ca90f1'
    const knowledgeObjectId = '0198be7a-1733-7d58-a0a5-799bc0a95927'
    const editor = createEditor({
      type: 'doc',
      content: [{
        type: 'paragraph',
        content: [{
          type: 'text',
          marks: [{ type: 'knowledgeOccurrence', attrs: { occurrenceId, knowledgeObjectId, objectType: 'entity' } }],
          text: 'Rocket Lab',
        }],
      }],
    })
    expect(editor.getJSON()).toEqual({
      type: 'doc',
      content: [{
        type: 'paragraph',
        content: [{
          type: 'text',
          marks: [{ type: 'knowledgeOccurrence', attrs: { occurrenceId, knowledgeObjectId, objectType: 'entity' } }],
          text: 'Rocket Lab',
        }],
      }],
    })
  })

  test('aggiorna il colore Highlight senza cambiare il testo', () => {
    const editor = createEditor({
      type: 'doc',
      content: [{ type: 'paragraph', content: [{ type: 'text', text: 'abc' }] }],
    })
    toggleHighlight(editor, 1, 4)
    expect(editor.chain().setTextSelection({ from: 1, to: 4 }).setMark('highlight', { color: '#4477dd' }).run()).toBe(true)
    expect(editor.getJSON()).toEqual({
      type: 'doc',
      content: [{
        type: 'paragraph',
        content: [{ type: 'text', marks: [{ type: 'highlight', attrs: { color: '#4477dd' } }], text: 'abc' }],
      }],
    })
  })

  test('estende il comando del popover a tutto il blocco Highlight contiguo', () => {
    const editor = createEditor({
      type: 'doc',
      content: [{
        type: 'paragraph',
        content: [
          { type: 'text', marks: [{ type: 'highlight', attrs: { color: '#f6dd79' } }], text: 'uno' },
          { type: 'text', marks: [{ type: 'bold' }, { type: 'highlight', attrs: { color: '#f6dd79' } }], text: 'due' },
          { type: 'text', text: ' tre' },
        ],
      }],
    })

    expect(editor.chain().setTextSelection(3).extendMarkRange('highlight').setMark('highlight', { color: '#c04080' }).run()).toBe(true)
    expect(editor.getJSON()).toEqual({
      type: 'doc',
      content: [{
        type: 'paragraph',
        content: [
          { type: 'text', marks: [{ type: 'highlight', attrs: { color: '#c04080' } }], text: 'uno' },
          { type: 'text', marks: [{ type: 'bold' }, { type: 'highlight', attrs: { color: '#c04080' } }], text: 'due' },
          { type: 'text', text: ' tre' },
        ],
      }],
    })

    expect(editor.chain().setTextSelection(3).extendMarkRange('highlight').unsetMark('highlight').run()).toBe(true)
    expect(JSON.stringify(editor.getJSON())).not.toContain('highlight')
    expect(JSON.stringify(editor.getJSON())).toContain(' tre')
  })

  test('estende highlight solo per input interno, non ai bordi', () => {
    const inside = createEditor({ type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'abcde' }] }] })
    toggleHighlight(inside, 2, 4)
    inside.chain().setTextSelection(3).insertContent('X').run()
    expect(markNamesForText(inside, 'X')).toContain('highlight')

    const before = createEditor({ type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'abcde' }] }] })
    toggleHighlight(before, 2, 4)
    before.chain().setTextSelection(2).insertContent('Y').run()
    expect(markNamesForText(before, 'Y')).not.toContain('highlight')

    const end = createEditor({ type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'abcde' }] }] })
    toggleHighlight(end, 2, 4)
    end.chain().setTextSelection(4).insertContent('Z').run()
    expect(markNamesForText(end, 'Z')).not.toContain('highlight')
  })

  test('conserva highlight dopo delete parziale e lo rimuove dopo delete totale', () => {
    const partial = createEditor({ type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'abcde' }] }] })
    toggleHighlight(partial, 2, 4)
    partial.chain().setTextSelection({ from: 2, to: 3 }).deleteSelection().run()
    expect(markNamesForText(partial, 'c')).toContain('highlight')

    const total = createEditor({ type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'abcde' }] }] })
    toggleHighlight(total, 2, 4)
    total.chain().setTextSelection({ from: 2, to: 4 }).deleteSelection().run()
    expect(JSON.stringify(total.getJSON())).not.toContain('highlight')
  })

  test('undo e redo ripristinano highlight', () => {
    const editor = createEditor({ type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'abc' }] }] })
    toggleHighlight(editor, 1, 4)
    expect(editor.isActive('highlight')).toBe(true)
    expect(editor.commands.undo()).toBe(true)
    expect(JSON.stringify(editor.getJSON())).not.toContain('highlight')
    expect(editor.commands.redo()).toBe(true)
    expect(JSON.stringify(editor.getJSON())).toContain('highlight')
  })

  test('clipboard ProseMirror conserva il solo mark visuale in copy e cut/paste', () => {
    const source = createEditor({ type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'abcde' }] }] })
    toggleHighlight(source, 2, 4)
    const copied = source.state.doc.slice(2, 4).content.toJSON()

    const copiedTarget = createEditor({ type: 'doc', content: [{ type: 'paragraph' }] })
    expect(copiedTarget.commands.insertContent(copied)).toBe(true)
    expect(JSON.stringify(copiedTarget.getJSON())).toContain('highlight')

    source.view.dispatch(source.state.tr.delete(2, 4))
    source.chain().setTextSelection(1).insertContent(copied).run()
    expect(JSON.stringify(source.getJSON())).toContain('highlight')
  })
})

const OCCURRENCE_ID = '0192a1b2-c3d4-7e5f-8a9b-0c1d2e3f0a01'
const CONCEPT_ID = '0192a1b2-c3d4-7e5f-8a9b-0c1d2e3fc001'
const DOCUMENT_ID = '0192a1b2-c3d4-7e5f-8a9b-0c1d2e3fd001'
const OTHER_DOCUMENT_ID = '0192a1b2-c3d4-7e5f-8a9b-0c1d2e3fd002'

type MarkJSON = NonNullable<JSONContent['marks']>[number]

function occurrenceMark(occurrenceId = OCCURRENCE_ID): MarkJSON {
  return { type: 'knowledgeOccurrence', attrs: { occurrenceId, knowledgeObjectId: CONCEPT_ID, objectType: 'concept' } }
}

/** Paragraph holding a single occurrence over the word "Backlog", at positions 1 to 8. */
const documentWithOccurrence: JSONContent = {
  type: 'doc',
  content: [{ type: 'paragraph', content: [{ type: 'text', marks: [occurrenceMark()], text: 'Backlog' }] }],
}

interface ClipboardHarness {
  editor: Editor
  generatedIds: string[]
  pasted: OccurrenceAttributes[]
}

function createClipboardEditor(content: JSONContent, documentId = DOCUMENT_ID): ClipboardHarness {
  const generatedIds: string[] = []
  const pasted: OccurrenceAttributes[] = []
  const createId = (): string => {
    const id = `0192a1b2-c3d4-7e5f-8a9b-0c1d2e3ff${String(generatedIds.length).padStart(3, '0')}`
    generatedIds.push(id)
    return id
  }
  const element = document.createElement('div')
  document.body.append(element)
  const editor = new Editor({
    element,
    extensions: [
      ...editorExtensions,
      occurrenceClipboardExtension({ documentId, createId, onPaste: (occurrences) => pasted.push(...occurrences) }),
    ],
    content,
  })
  editors.push(editor)
  return { editor, generatedIds, pasted }
}

function clipboardEventWith(payload: string | null): ClipboardEvent {
  return {
    clipboardData: { getData: (format: string) => (format === CUT_CLIPBOARD_FORMAT ? payload ?? '' : '') },
  } as unknown as ClipboardEvent
}

function runClipboardHandler(editor: Editor, name: 'copy' | 'cut' | 'paste', payload: string | null = null): void {
  editor.view.someProp('handleDOMEvents', (handlers) => {
    handlers?.[name]?.(editor.view, clipboardEventWith(payload) as never)
    return false
  })
}

function transformPastedSlice(editor: Editor, slice: Slice): Slice {
  return editor.view.someProp('transformPasted', (transform) => transform(slice, editor.view, false)) ?? slice
}

function occurrenceIdsOf(editor: Editor): string[] {
  return [...collectOccurrences(editor.getJSON()).keys()]
}

/** Occurrence IDs carried by a clipboard slice, whose content may be bare inline nodes. */
function occurrenceIdsInSlice(slice: Slice): Set<string> {
  const nodes = (slice.content.toJSON() as JSONContent[] | null) ?? []
  const texts = nodes.flatMap((node) => (node.type === 'text' ? [node] : node.content ?? []))
  return new Set(
    texts
      .flatMap((node) => node.marks ?? [])
      .filter((mark) => mark.type === 'knowledgeOccurrence')
      .map((mark) => mark.attrs?.occurrenceId as string),
  )
}

function pasteInto(editor: Editor, slice: Slice, payload: string | null): Slice {
  runClipboardHandler(editor, 'paste', payload)
  const transformed = transformPastedSlice(editor, slice)
  editor.view.dispatch(editor.state.tr.replaceSelection(transformed))
  return transformed
}

describe('invarianti delle KnowledgeOccurrence (FASE 4)', () => {
  test('INV-OCC-06: l’editing interno conserva ID, KnowledgeObject e discriminator', () => {
    const editor = createEditor(documentWithOccurrence)
    editor.chain().setTextSelection(4).insertContent('X').run()

    const occurrences = collectOccurrences(editor.getJSON())
    expect(editor.state.doc.textContent).toBe('BacXklog')
    expect([...occurrences.values()]).toEqual([
      { occurrenceId: OCCURRENCE_ID, knowledgeObjectId: CONCEPT_ID, objectType: 'concept' },
    ])
  })

  test('INV-OCC-06: digitare esattamente prima o dopo il range non lo estende', () => {
    const editor = createEditor(documentWithOccurrence)
    editor.chain().setTextSelection(8).insertContent('!').run()
    editor.chain().setTextSelection(1).insertContent('«').run()

    expect(editor.state.doc.textContent).toBe('«Backlog!')
    expect(editor.getHTML()).toContain('class="nectrix-knowledge-occurrence">Backlog</span>')
    expect(occurrenceIdsOf(editor)).toEqual([OCCURRENCE_ID])
  })

  test('INV-OCC-07: la cancellazione parziale conserva identità sul testo residuo', () => {
    const editor = createEditor(documentWithOccurrence)
    editor.chain().setTextSelection({ from: 1, to: 5 }).deleteSelection().run()

    expect(editor.state.doc.textContent).toBe('log')
    expect(occurrenceIdsOf(editor)).toEqual([OCCURRENCE_ID])
  })

  test('INV-OCC-08: la cancellazione totale rimuove il mark senza toccare altro', () => {
    const editor = createEditor(documentWithOccurrence)
    editor.chain().setTextSelection({ from: 1, to: 8 }).deleteSelection().run()

    expect(occurrenceIdsOf(editor)).toEqual([])
    expect(editor.getJSON()).toEqual({ type: 'doc', content: [{ type: 'paragraph' }] })
  })

  test('INV-OCC-09: undo ripristina lo stesso ID e redo riproduce la rimozione', () => {
    const editor = createEditor(documentWithOccurrence)
    editor.chain().setTextSelection({ from: 1, to: 8 }).deleteSelection().run()
    expect(occurrenceIdsOf(editor)).toEqual([])

    expect(editor.commands.undo()).toBe(true)
    expect(occurrenceIdsOf(editor)).toEqual([OCCURRENCE_ID])
    expect(editor.getJSON()).toEqual(documentWithOccurrence)

    expect(editor.commands.redo()).toBe(true)
    expect(occurrenceIdsOf(editor)).toEqual([])
  })

  test('INV-OCC-12: il round trip JSON e HTML non rigenera gli ID', () => {
    const editor = createEditor(documentWithOccurrence)
    const html = editor.getHTML()
    expect(html).toContain(`data-occurrence-id="${OCCURRENCE_ID}"`)

    expect(createEditor(editor.getJSON()).getJSON()).toEqual(documentWithOccurrence)
    expect(occurrenceIdsOf(createEditor(html))).toEqual([OCCURRENCE_ID])
  })

  test('INV-OCC-15: un mark manipolato non diventa occurrence e conserva il testo', () => {
    const manipulated = '<p><span data-occurrence-id="non-un-uuid" data-knowledge-object-id="'
      + CONCEPT_ID + '" data-object-type="concept">testo</span></p>'
    const editor = createEditor(manipulated)

    expect(occurrenceIdsOf(editor)).toEqual([])
    expect(editor.state.doc.textContent).toBe('testo')
  })

  test('INV-OCC-15: un discriminator sconosciuto non diventa occurrence', () => {
    const manipulated = `<p><span data-occurrence-id="${OCCURRENCE_ID}" data-knowledge-object-id="`
      + CONCEPT_ID + '" data-object-type="context">testo</span></p>'

    expect(occurrenceIdsOf(createEditor(manipulated))).toEqual([])
  })
})

/** Sends a real keydown through the editor keymaps, without replaying it as a command. */
function pressKey(editor: Editor, key: string): boolean {
  const event = new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true })
  return editor.view.someProp('handleKeyDown', (handler) => handler(editor.view, event)) ?? false
}

describe('caret dopo una selezione non vuota', () => {
  test('ArrowRight collassa alla fine della selezione e il testo successivo si aggiunge', () => {
    const editor = createEditor(documentWithOccurrence)
    editor.commands.selectAll()

    expect(pressKey(editor, 'ArrowRight')).toBe(true)
    expect(editor.state.selection.empty).toBe(true)
    expect(editor.state.selection.from).toBe(8)

    editor.commands.insertContent('!')
    expect(editor.state.doc.textContent).toBe('Backlog!')
    expect(occurrenceIdsOf(editor)).toEqual([OCCURRENCE_ID])
  })

  test('ArrowLeft collassa all’inizio della selezione', () => {
    const editor = createEditor(documentWithOccurrence)
    editor.commands.selectAll()

    expect(pressKey(editor, 'ArrowLeft')).toBe(true)
    expect(editor.state.selection.empty).toBe(true)
    expect(editor.state.selection.from).toBe(1)

    editor.commands.insertContent('«')
    expect(editor.state.doc.textContent).toBe('«Backlog')
  })

  test('con il caret già collassato il movimento resta al browser', () => {
    const editor = createEditor(documentWithOccurrence)
    editor.commands.setTextSelection(4)

    expect(pressKey(editor, 'ArrowRight')).toBe(false)
    expect(editor.state.selection.from).toBe(4)
  })
})

describe('clipboard delle KnowledgeOccurrence (FASE 4)', () => {
  test('INV-OCC-10: il paste da copia crea un nuovo ID e mantiene il KnowledgeObject', () => {
    const { editor, pasted } = createClipboardEditor(documentWithOccurrence)
    const copied = editor.state.doc.slice(1, 8)
    editor.commands.setTextSelection(8)

    pasteInto(editor, copied, null)

    const occurrences = collectOccurrences(editor.getJSON())
    expect(occurrences.size).toBe(2)
    const ids = [...occurrences.keys()]
    expect(ids).toContain(OCCURRENCE_ID)
    expect(ids.filter((id) => id !== OCCURRENCE_ID)).toHaveLength(1)
    expect([...occurrences.values()].every((occurrence) => occurrence.knowledgeObjectId === CONCEPT_ID)).toBe(true)
    expect(pasted.map((occurrence) => occurrence.occurrenceId)).toEqual(ids.filter((id) => id !== OCCURRENCE_ID))
  })

  test('INV-OCC-10: frammenti contigui della stessa occurrence ricevono lo stesso nuovo ID', () => {
    const fragmented: JSONContent = {
      type: 'doc',
      content: [{
        type: 'paragraph',
        content: [
          { type: 'text', marks: [occurrenceMark()], text: 'Rocket' },
          { type: 'text', marks: [occurrenceMark(), { type: 'bold' }], text: ' Lab' },
        ],
      }],
    }
    const { editor } = createClipboardEditor(fragmented)
    const copied = editor.state.doc.slice(1, 11)
    editor.commands.setTextSelection(11)

    const transformed = pasteInto(editor, copied, null)

    const pastedIds = occurrenceIdsInSlice(transformed)
    expect(pastedIds.size).toBe(1)
    expect(pastedIds.has(OCCURRENCE_ID)).toBe(false)
    expect(collectOccurrences(editor.getJSON()).size).toBe(2)
  })

  test('INV-OCC-11: il cut/paste verificato nello stesso documento conserva l’ID', () => {
    const { editor, generatedIds } = createClipboardEditor(documentWithOccurrence)
    editor.commands.setTextSelection({ from: 1, to: 8 })
    const cut = editor.state.selection.content()

    runClipboardHandler(editor, 'cut')
    editor.commands.deleteSelection()
    const proof = JSON.stringify({ nonce: generatedIds[0], documentId: DOCUMENT_ID, fingerprint: sliceFingerprint(cut) })
    pasteInto(editor, cut, proof)

    expect(occurrenceIdsOf(editor)).toEqual([OCCURRENCE_ID])
  })

  test('INV-OCC-11: lo stesso taglio non conserva l’ID una seconda volta', () => {
    const { editor, generatedIds } = createClipboardEditor(documentWithOccurrence)
    editor.commands.setTextSelection({ from: 1, to: 8 })
    const cut = editor.state.selection.content()

    runClipboardHandler(editor, 'cut')
    editor.commands.deleteSelection()
    const proof = JSON.stringify({ nonce: generatedIds[0], documentId: DOCUMENT_ID, fingerprint: sliceFingerprint(cut) })
    pasteInto(editor, cut, proof)
    pasteInto(editor, cut, proof)

    const ids = occurrenceIdsOf(editor)
    expect(ids).toHaveLength(2)
    expect(ids).toContain(OCCURRENCE_ID)
    expect(new Set(ids).size).toBe(2)
  })

  test('INV-OCC-11: senza formato clipboard Nectrix il cut/paste rigenera l’ID', () => {
    const { editor } = createClipboardEditor(documentWithOccurrence)
    editor.commands.setTextSelection({ from: 1, to: 8 })
    const cut = editor.state.selection.content()

    runClipboardHandler(editor, 'cut')
    editor.commands.deleteSelection()
    pasteInto(editor, cut, null)

    expect(occurrenceIdsOf(editor)).not.toContain(OCCURRENCE_ID)
  })

  test('INV-OCC-11: una prova proveniente da un altro Document non conserva l’ID', () => {
    const { editor, generatedIds } = createClipboardEditor(documentWithOccurrence, OTHER_DOCUMENT_ID)
    editor.commands.setTextSelection({ from: 1, to: 8 })
    const cut = editor.state.selection.content()

    runClipboardHandler(editor, 'cut')
    editor.commands.deleteSelection()
    const proof = JSON.stringify({
      nonce: generatedIds[0],
      documentId: DOCUMENT_ID,
      fingerprint: sliceFingerprint(cut),
    })
    pasteInto(editor, cut, proof)

    const ids = occurrenceIdsOf(editor)
    expect(ids).not.toContain(OCCURRENCE_ID)
    expect(ids).toHaveLength(1)
  })

  test('INV-OCC-11: con l’originale ancora presente il paste rigenera l’ID', () => {
    const { editor, generatedIds } = createClipboardEditor(documentWithOccurrence)
    editor.commands.setTextSelection({ from: 1, to: 8 })
    const cut = editor.state.selection.content()

    runClipboardHandler(editor, 'cut')
    editor.commands.setTextSelection(8)
    const proof = JSON.stringify({ nonce: generatedIds[0], documentId: DOCUMENT_ID, fingerprint: sliceFingerprint(cut) })
    pasteInto(editor, cut, proof)

    const ids = occurrenceIdsOf(editor)
    expect(ids).toHaveLength(2)
    expect(new Set(ids).size).toBe(2)
  })

  test('INV-OCC-11: un copy dopo il cut invalida la prova e rigenera l’ID', () => {
    const { editor, generatedIds } = createClipboardEditor(documentWithOccurrence)
    editor.commands.setTextSelection({ from: 1, to: 8 })
    const cut = editor.state.selection.content()

    runClipboardHandler(editor, 'cut')
    editor.commands.deleteSelection()
    const proof = JSON.stringify({ nonce: generatedIds[0], documentId: DOCUMENT_ID, fingerprint: sliceFingerprint(cut) })
    runClipboardHandler(editor, 'copy')
    pasteInto(editor, cut, proof)

    expect(occurrenceIdsOf(editor)).not.toContain(OCCURRENCE_ID)
  })
})

describe('maniglie di confine delle occurrence', () => {
  /** Paragraph "Backlog utile", with the occurrence on "Backlog" at 1..8. */
  const withTail: JSONContent = {
    type: 'doc',
    content: [{
      type: 'paragraph',
      content: [
        { type: 'text', marks: [occurrenceMark()], text: 'Backlog' },
        { type: 'text', text: ' utile' },
      ],
    }],
  }

  test('trova l’intero intervallo anche partendo da un frammento successivo', () => {
    // Come nei documenti reali: solo una parte dell'occurrence è evidenziata, quindi il testo è
    // spezzato in più text node contigui.
    const editor = createEditor({
      type: 'doc',
      content: [{
        type: 'paragraph',
        content: [
          { type: 'text', marks: [{ type: 'highlight', attrs: { color: '#f6dd79' } }], text: 'Gli a' },
          { type: 'text', marks: [{ type: 'highlight', attrs: { color: '#f6dd79' } }, occurrenceMark()], text: 'rchetipi' },
          { type: 'text', marks: [occurrenceMark()], text: ' junghiani' },
          { type: 'text', text: ' e altro' },
        ],
      }],
    })

    const fromFirstFragment = occurrenceRangeAt(editor.state, 7)
    const fromSecondFragment = occurrenceRangeAt(editor.state, 15)

    expect(fromFirstFragment?.from).toBe(6)
    expect(fromFirstFragment?.to).toBe(24)
    expect(fromSecondFragment?.from).toBe(6)
    expect(fromSecondFragment?.to).toBe(24)
    expect(occurrenceRangeAt(editor.state, 28)).toBeNull()
  })

  test('trova l’intervallo dell’occurrence, frammenti contigui inclusi', () => {
    const editor = createEditor({
      type: 'doc',
      content: [{
        type: 'paragraph',
        content: [
          { type: 'text', marks: [occurrenceMark()], text: 'Rocket' },
          { type: 'text', marks: [occurrenceMark(), { type: 'bold' }], text: ' Lab' },
        ],
      }],
    })

    const range = occurrenceRangeAt(editor.state, 3)
    expect(range?.from).toBe(1)
    expect(range?.to).toBe(11)
    expect(occurrenceRangeAt(editor.state, 0)).toBeNull()
  })

  test('spostare la fine estende l’intervallo mantenendo lo stesso occurrenceId', () => {
    const editor = createEditor(withTail)
    const range = occurrenceRangeAt(editor.state, 2)

    expect(moveOccurrenceBoundary(editor.view, range!, 'end', 14)).toBe(true)

    const occurrences = collectOccurrences(editor.getJSON())
    expect([...occurrences.values()]).toEqual([
      { occurrenceId: OCCURRENCE_ID, knowledgeObjectId: CONCEPT_ID, objectType: 'concept' },
    ])
    expect(editor.getHTML()).toContain('>Backlog utile</span>')
  })

  test('spostare l’inizio riduce l’intervallo senza cambiare identità', () => {
    const editor = createEditor(withTail)
    const range = occurrenceRangeAt(editor.state, 2)

    expect(moveOccurrenceBoundary(editor.view, range!, 'start', 4)).toBe(true)

    expect(occurrenceIdsOf(editor)).toEqual([OCCURRENCE_ID])
    expect(editor.getHTML()).toContain('>klog</span>')
  })

  test('un intervallo vuoto o immutato viene rifiutato', () => {
    const editor = createEditor(withTail)
    const range = occurrenceRangeAt(editor.state, 2)

    expect(moveOccurrenceBoundary(editor.view, range!, 'end', 1)).toBe(false)
    expect(moveOccurrenceBoundary(editor.view, range!, 'end', 8)).toBe(false)
    expect(occurrenceIdsOf(editor)).toEqual([OCCURRENCE_ID])
  })

  test('il confine non entra dentro un’altra occurrence', () => {
    const other = '0192a1b2-c3d4-7e5f-8a9b-0c1d2e3f0b01'
    const editor = createEditor({
      type: 'doc',
      content: [{
        type: 'paragraph',
        content: [
          { type: 'text', marks: [occurrenceMark()], text: 'Primo' },
          { type: 'text', text: ' ' },
          { type: 'text', marks: [occurrenceMark(other)], text: 'Secondo' },
        ],
      }],
    })
    const range = occurrenceRangeAt(editor.state, 2)

    expect(moveOccurrenceBoundary(editor.view, range!, 'end', 10)).toBe(false)
    expect(moveOccurrenceBoundary(editor.view, range!, 'end', 7)).toBe(true)
    expect(collectOccurrences(editor.getJSON()).size).toBe(2)
  })

  test('il confine resta dentro il proprio textblock', () => {
    const editor = createEditor({
      type: 'doc',
      content: [
        { type: 'paragraph', content: [{ type: 'text', marks: [occurrenceMark()], text: 'Backlog' }] },
        { type: 'paragraph', content: [{ type: 'text', text: 'Altro paragrafo' }] },
      ],
    })
    const range = occurrenceRangeAt(editor.state, 2)

    expect(moveOccurrenceBoundary(editor.view, range!, 'end', 20)).toBe(false)
    expect(editor.getJSON().content?.[1]?.content?.[0]?.marks ?? []).toEqual([])
  })
})

describe('un Concept non si evidenzia', () => {
  const mixed: JSONContent = {
    type: 'doc',
    content: [{
      type: 'paragraph',
      content: [
        { type: 'text', text: 'Gli ' },
        { type: 'text', marks: [occurrenceMark()], text: 'archetipi' },
        { type: 'text', text: ' sono' },
      ],
    }],
  }

  test('gli intervalli evidenziabili escludono le occurrence', () => {
    const editor = createEditor(mixed)

    expect(occurrenceFreeRanges(editor.state, 1, 19)).toEqual([{ from: 1, to: 5 }, { from: 14, to: 19 }])
  })

  test('una selezione tutta dentro l’occurrence non lascia spazio all’evidenziazione', () => {
    const editor = createEditor(mixed)

    expect(occurrenceFreeRanges(editor.state, 6, 12)).toEqual([])
  })

  test('un testo senza occurrence resta interamente evidenziabile', () => {
    const editor = createEditor({
      type: 'doc',
      content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Testo libero' }] }],
    })

    expect(occurrenceFreeRanges(editor.state, 1, 13)).toEqual([{ from: 1, to: 13 }])
  })
})
