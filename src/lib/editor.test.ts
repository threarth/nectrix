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

describe('schema editoriale della FASE 1', () => {
  test('espone solo i nodi e mark persistenti in allowlist', () => {
    const editor = createEditor(richDocument)
    expect(Object.keys(editor.schema.nodes).sort()).toEqual(
      ['blockquote', 'bulletList', 'doc', 'heading', 'listItem', 'orderedList', 'paragraph', 'text'].sort(),
    )
    expect(Object.keys(editor.schema.marks).sort()).toEqual(['bold', 'italic', 'underline'])
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
})
