// SPDX-License-Identifier: AGPL-3.0-or-later

import { Editor, type JSONContent } from '@tiptap/core'
import { afterEach, describe, expect, test } from 'vitest'
import { editorExtensions } from './editor'

const editors: Editor[] = []

function createEditor(content: JSONContent): Editor {
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
      ['blockquote', 'bulletList', 'doc', 'heading', 'listItem', 'orderedList', 'paragraph', 'text'].sort(),
    )
    expect(Object.keys(editor.schema.marks).sort()).toEqual(['bold', 'highlight', 'italic', 'knowledgeOccurrence', 'underline'])
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
