// SPDX-License-Identifier: AGPL-3.0-or-later

import { Mark } from '@tiptap/core'
import { StarterKit } from '@tiptap/starter-kit'

export type HighlightColor = string

export const defaultHighlightColors = [
  '#f6dd79',
  '#bde6be',
  '#b8dff4',
  '#f4c6d8',
  '#e6c4a8',
  '#d8c5ed',
  '#aee1dc',
  '#f3b8b1',
  '#cbd5a8',
  '#c6d4ec',
] as const

const legacyHighlightColors: Record<string, string> = {
  yellow: '#f6dd79',
  green: '#bde6be',
  blue: '#b8dff4',
  pink: '#f4c6d8',
}

const hexColor = /^#[0-9a-f]{6}$/i

export function isHighlightColor(value: unknown): value is HighlightColor {
  return typeof value === 'string' && (hexColor.test(value) || value in legacyHighlightColors)
}

export function normalizeHighlightColor(value: unknown): HighlightColor {
  if (typeof value === 'string' && value in legacyHighlightColors) return legacyHighlightColors[value]
  return isHighlightColor(value) ? value.toLowerCase() : defaultHighlightColors[0]
}

const Highlight = Mark.create({
  name: 'highlight',
  inclusive: false,

  addAttributes() {
    return {
      color: {
        default: defaultHighlightColors[0],
        parseHTML: (element) => {
          const color = element.getAttribute('data-highlight-color')
          return normalizeHighlightColor(color)
        },
        renderHTML: (attributes) => {
          const color = normalizeHighlightColor(attributes.color)
          return { 'data-highlight-color': color, style: `background-color: ${color}` }
        },
      },
    }
  },

  parseHTML() {
    return [{ tag: 'mark' }]
  },

  renderHTML({ HTMLAttributes }) {
    return ['mark', { ...HTMLAttributes, class: 'nectrix-highlight' }, 0]
  },
})

const KnowledgeOccurrence = Mark.create({
  name: 'knowledgeOccurrence',
  inclusive: false,

  addAttributes() {
    return {
      occurrenceId: { default: null },
      knowledgeObjectId: { default: null },
      objectType: { default: null },
    }
  },

  parseHTML() {
    return []
  },

  renderHTML({ HTMLAttributes }) {
    return ['span', { ...HTMLAttributes, class: 'nectrix-knowledge-occurrence' }, 0]
  },
})

export const editorExtensions = [
  StarterKit.configure({
    code: false,
    codeBlock: false,
    hardBreak: false,
    horizontalRule: false,
    link: false,
    strike: false,
    trailingNode: false,
    heading: {
      levels: [1, 2, 3, 4, 5, 6],
    },
  }),
  Highlight,
  KnowledgeOccurrence,
]
