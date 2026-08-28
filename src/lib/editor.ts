// SPDX-License-Identifier: AGPL-3.0-or-later

import { Extension, Mark, type Editor } from '@tiptap/core'
import { AllSelection, Plugin, PluginKey, TextSelection } from '@tiptap/pm/state'
import type { EditorView } from '@tiptap/pm/view'
import type { Slice } from '@tiptap/pm/model'
import { StarterKit } from '@tiptap/starter-kit'
import {
  canKeepCutOccurrenceIds,
  collectOccurrences,
  collectSliceOccurrenceRuns,
  CUT_CLIPBOARD_FORMAT,
  isOccurrenceAttributes,
  parseCutClipboardPayload,
  planOccurrenceIdRewrite,
  remapSliceOccurrences,
  sliceFingerprint,
  type CutClipboardPayload,
  type CutToken,
  type OccurrenceAttributes,
} from './occurrences'

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

/** Explicit HTML round trip: only these data attributes carry the occurrence across the clipboard. */
const OCCURRENCE_DATA_ATTRIBUTES = {
  occurrenceId: 'data-occurrence-id',
  knowledgeObjectId: 'data-knowledge-object-id',
  objectType: 'data-object-type',
} as const

function occurrenceAttributeSpec(name: keyof typeof OCCURRENCE_DATA_ATTRIBUTES) {
  const attribute = OCCURRENCE_DATA_ATTRIBUTES[name]
  return {
    default: null,
    parseHTML: (element: HTMLElement) => element.getAttribute(attribute),
    renderHTML: (attributes: Record<string, unknown>) => ({ [attribute]: attributes[name] }),
  }
}

const KnowledgeOccurrence = Mark.create({
  name: 'knowledgeOccurrence',
  inclusive: false,

  addAttributes() {
    return {
      occurrenceId: occurrenceAttributeSpec('occurrenceId'),
      knowledgeObjectId: occurrenceAttributeSpec('knowledgeObjectId'),
      objectType: occurrenceAttributeSpec('objectType'),
    }
  },

  parseHTML() {
    return [{
      tag: `span[${OCCURRENCE_DATA_ATTRIBUTES.occurrenceId}]`,
      // INV-OCC-15: incomplete or manipulated attributes never become an occurrence mark.
      getAttrs: (element: HTMLElement) => {
        const attributes = {
          occurrenceId: element.getAttribute(OCCURRENCE_DATA_ATTRIBUTES.occurrenceId),
          knowledgeObjectId: element.getAttribute(OCCURRENCE_DATA_ATTRIBUTES.knowledgeObjectId),
          objectType: element.getAttribute(OCCURRENCE_DATA_ATTRIBUTES.objectType),
        }
        return isOccurrenceAttributes(attributes) ? attributes : false
      },
    }]
  },

  renderHTML({ HTMLAttributes }) {
    return ['span', { ...HTMLAttributes, class: 'nectrix-knowledge-occurrence' }, 0]
  },
})

/**
 * Collapses a non empty selection to the edge the browser would move to, in the same transaction as
 * the key press. Without this the state selection depends on the asynchronous `selectionchange`
 * event: a keystroke arriving before it is applied to the previous selection, so typing right after
 * a select all can replace the whole document instead of inserting at the caret.
 */
function collapseSelectionToEdge(editor: Editor, side: 1 | -1): boolean {
  const { selection } = editor.state
  if (selection.empty) return false
  if (!(selection instanceof TextSelection || selection instanceof AllSelection)) return false
  return editor.commands.setTextSelection(side === 1 ? selection.to : selection.from)
}

const SelectionCaret = Extension.create({
  name: 'selectionCaret',

  addKeyboardShortcuts() {
    return {
      ArrowLeft: ({ editor }) => collapseSelectionToEdge(editor, -1),
      ArrowRight: ({ editor }) => collapseSelectionToEdge(editor, 1),
    }
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
  SelectionCaret,
]

export interface OccurrenceClipboardOptions {
  /** Document owning the editor: an ID can only survive a cut inside the same Document. */
  documentId: string
  /** Generates occurrence IDs and cut nonces. Injectable to keep the behaviour testable. */
  createId: () => string
  /** Occurrences actually inserted by a paste, to be verified against the database. */
  onPaste: (occurrences: OccurrenceAttributes[]) => void
}

export const occurrenceClipboardKey = new PluginKey('occurrenceClipboard')

function uniqueByOccurrenceId(occurrences: OccurrenceAttributes[]): OccurrenceAttributes[] {
  const unique = new Map(occurrences.map((occurrence) => [occurrence.occurrenceId, occurrence]))
  return [...unique.values()]
}

/**
 * Clipboard memory of one editor: the last internal cut and the proof read from the last paste.
 * A copy always produces new IDs, only a cut proved by the custom Nectrix format inside the same
 * Document can keep them, and every ambiguous case falls back to new IDs.
 */
class OccurrenceClipboard {
  private cutToken: CutToken | null = null
  private pastedPayload: CutClipboardPayload | null = null

  constructor(private readonly options: OccurrenceClipboardOptions) {}

  /** Records the slice being cut, before ProseMirror deletes the selection. */
  rememberCut(view: EditorView): void {
    if (view.state.selection.empty) return
    const slice = view.state.selection.content()
    this.cutToken = {
      nonce: this.options.createId(),
      documentId: this.options.documentId,
      fingerprint: sliceFingerprint(slice),
      occurrenceIds: collectSliceOccurrenceRuns(slice).map((run) => run.occurrenceId),
      consumed: false,
    }
  }

  /** A new copy, a destroyed editor or a consumed token leave no provable cut behind. */
  forgetCut(): void {
    this.cutToken = null
  }

  readPastePayload(event: ClipboardEvent): void {
    this.pastedPayload = parseCutClipboardPayload(event.clipboardData?.getData(CUT_CLIPBOARD_FORMAT) ?? null)
  }

  writeCutProof(event: ClipboardEvent): void {
    if (this.cutToken === null || event.clipboardData === null) return
    const payload: CutClipboardPayload = {
      nonce: this.cutToken.nonce,
      documentId: this.cutToken.documentId,
      fingerprint: this.cutToken.fingerprint,
    }
    event.clipboardData.setData(CUT_CLIPBOARD_FORMAT, JSON.stringify(payload))
  }

  transformPasted(slice: Slice, view: EditorView): Slice {
    const runs = collectSliceOccurrenceRuns(slice)
    const payload = this.pastedPayload
    this.pastedPayload = null
    if (runs.length === 0) return slice

    if (this.canKeepIds(slice, runs, payload, view)) {
      if (this.cutToken !== null) this.cutToken = { ...this.cutToken, consumed: true }
      this.options.onPaste(uniqueByOccurrenceId(runs))
      return slice
    }

    const mapping = planOccurrenceIdRewrite(runs, this.options.createId)
    const pasted = runs.map((run) => ({ ...run, occurrenceId: mapping.get(run.occurrenceId) ?? run.occurrenceId }))
    this.options.onPaste(uniqueByOccurrenceId(pasted))
    return remapSliceOccurrences(slice, mapping)
  }

  private canKeepIds(
    slice: Slice,
    runs: readonly OccurrenceAttributes[],
    payload: CutClipboardPayload | null,
    view: EditorView,
  ): boolean {
    return canKeepCutOccurrenceIds({
      token: this.cutToken,
      payload,
      documentId: this.options.documentId,
      fingerprint: sliceFingerprint(slice),
      pastedIds: runs.map((run) => run.occurrenceId),
      presentIds: new Set(collectOccurrences(view.state.doc.toJSON()).keys()),
    })
  }
}

function createOccurrenceClipboardPlugin(options: OccurrenceClipboardOptions): Plugin {
  const clipboard = new OccurrenceClipboard(options)
  const writeCutProof = (event: ClipboardEvent): void => clipboard.writeCutProof(event)

  return new Plugin({
    key: occurrenceClipboardKey,
    props: {
      // Runs before the ProseMirror clipboard handlers: reads the proof, records the cut.
      handleDOMEvents: {
        copy: () => {
          clipboard.forgetCut()
          return false
        },
        cut: (view) => {
          clipboard.rememberCut(view)
          return false
        },
        paste: (_view, event) => {
          clipboard.readPastePayload(event)
          return false
        },
      },
      transformPasted: (slice, view) => clipboard.transformPasted(slice, view),
    },
    // Registered after the ProseMirror handlers, which clear the clipboard before writing to it.
    view: (view) => {
      view.dom.addEventListener('cut', writeCutProof)
      return {
        destroy: () => {
          view.dom.removeEventListener('cut', writeCutProof)
          clipboard.forgetCut()
        },
      }
    },
  })
}

/** Clipboard rules of the knowledgeOccurrence mark, bound to the Document being edited. */
export function occurrenceClipboardExtension(options: OccurrenceClipboardOptions): Extension {
  return Extension.create({
    name: 'occurrenceClipboard',
    addProseMirrorPlugins() {
      return [createOccurrenceClipboardPlugin(options)]
    },
  })
}

/**
 * Removes the knowledgeOccurrence mark of the given occurrences, keeping text and other marks.
 * Used when a pasted mark cannot be trusted: nothing is created and nothing else is rewritten.
 */
export function removeOccurrenceMarks(editor: Editor, occurrenceIds: ReadonlySet<string>): boolean {
  const markType = editor.schema.marks.knowledgeOccurrence
  const transaction = editor.state.tr
  let removed = false

  editor.state.doc.descendants((node, position) => {
    if (!node.isText) return true
    for (const mark of node.marks) {
      if (mark.type !== markType) continue
      if (!occurrenceIds.has(mark.attrs.occurrenceId as string)) continue
      transaction.removeMark(position, position + node.nodeSize, mark)
      removed = true
    }
    return true
  })

  if (removed) editor.view.dispatch(transaction)
  return removed
}
