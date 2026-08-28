// SPDX-License-Identifier: AGPL-3.0-or-later

import { Extension, Mark, type Editor } from '@tiptap/core'
import { AllSelection, Plugin, PluginKey, TextSelection, type EditorState } from '@tiptap/pm/state'
import { Decoration, DecorationSet, type EditorView } from '@tiptap/pm/view'
import type { Mark as ProseMirrorMark, Slice } from '@tiptap/pm/model'
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
import { occurrenceHandleStrings } from './strings'

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

/**
 * Draggable handles on the boundaries of the occurrence under the caret. Moving them keeps the
 * same occurrenceId: the range is corrected, not replaced by a different occurrence.
 */
const OccurrenceHandles = Extension.create({
  name: 'occurrenceHandles',

  addProseMirrorPlugins() {
    const editor = this.editor
    return [
      new Plugin({
        key: new PluginKey('occurrenceHandles'),
        props: {
          decorations(state) {
            const range = editor.isEditable ? handledOccurrence(state) : null
            if (range === null) return null
            return DecorationSet.create(state.doc, [
              Decoration.widget(range.from, (view) => boundaryHandle(view, 'start'), { side: -1, stopEvent: () => true }),
              Decoration.widget(range.to, (view) => boundaryHandle(view, 'end'), { side: 1, stopEvent: () => true }),
            ])
          },
        },
      }),
    ]
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
  OccurrenceHandles,
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

/** Occurrence range containing a position, or null when the position carries no occurrence. */
export function occurrenceRangeAt(state: EditorState, position: number): OccurrenceRange | null {
  const markType = state.schema.marks.knowledgeOccurrence
  const resolved = state.doc.resolve(position)
  const parent = resolved.parent
  if (!parent.isTextblock) return null

  const blockStart = resolved.start()
  let from = blockStart
  let found: ProseMirrorMark | null = null
  let rangeFrom = 0
  let rangeTo = 0

  parent.forEach((child, offset) => {
    const childFrom = blockStart + offset
    const childTo = childFrom + child.nodeSize
    const mark = child.marks.find((candidate) => candidate.type === markType) ?? null
    if (found !== null) {
      if (mark !== null && mark.attrs.occurrenceId === found.attrs.occurrenceId && childFrom === rangeTo) {
        rangeTo = childTo
      }
      return
    }
    if (mark === null || position < childFrom || position > childTo) return
    found = mark
    rangeFrom = childFrom
    rangeTo = childTo
  })

  from = rangeFrom
  return found === null ? null : { from, to: rangeTo, mark: found, blockStart, blockEnd: resolved.end() }
}

export interface OccurrenceRange {
  from: number
  to: number
  mark: ProseMirrorMark
  blockStart: number
  blockEnd: number
}

/**
 * Moves one boundary of an occurrence keeping its identity: the same mark is removed from the old
 * range and applied to the new one, so occurrenceId, knowledgeObjectId and objectType survive.
 * The range stays inside its textblock, never empties and never overlaps another occurrence.
 */
export function moveOccurrenceBoundary(view: EditorView, range: OccurrenceRange, side: 'start' | 'end', position: number): boolean {
  const target = Math.max(range.blockStart, Math.min(position, range.blockEnd))
  const from = side === 'start' ? target : range.from
  const to = side === 'start' ? range.to : target
  if (from >= to || (from === range.from && to === range.to)) return false
  if (overlapsAnotherOccurrence(view.state, from, to, range.mark)) return false

  const transaction = view.state.tr
    .removeMark(range.from, range.to, range.mark)
    .addMark(from, to, range.mark)
  view.dispatch(transaction)
  return true
}

function overlapsAnotherOccurrence(state: EditorState, from: number, to: number, mark: ProseMirrorMark): boolean {
  const markType = state.schema.marks.knowledgeOccurrence
  let overlaps = false
  state.doc.nodesBetween(from, to, (node) => {
    if (!node.isText || overlaps) return true
    const other = node.marks.find((candidate) => candidate.type === markType)
    if (other !== undefined && other.attrs.occurrenceId !== mark.attrs.occurrenceId) overlaps = true
    return true
  })
  return overlaps
}

/** Occurrence whose boundaries can be moved right now, that is the one holding the selection. */
function handledOccurrence(state: EditorState): OccurrenceRange | null {
  const range = occurrenceRangeAt(state, state.selection.from)
  if (range === null) return null
  return state.selection.to <= range.to ? range : null
}

function startBoundaryDrag(view: EditorView, side: 'start' | 'end', event: PointerEvent): void {
  const range = handledOccurrence(view.state)
  if (range === null || !view.editable) return
  event.preventDefault()
  event.stopPropagation()

  // Position that stays inside the occurrence while the opposite boundary moves.
  const anchor = side === 'start' ? range.to - 1 : range.from + 1

  const move = (moveEvent: PointerEvent): void => {
    const coordinates = view.posAtCoords({ left: moveEvent.clientX, top: moveEvent.clientY })
    const current = occurrenceRangeAt(view.state, anchor)
    if (coordinates === null || current === null) return
    moveOccurrenceBoundary(view, current, side, coordinates.pos)
  }

  const stop = (): void => {
    window.removeEventListener('pointermove', move)
    window.removeEventListener('pointerup', stop)
    window.removeEventListener('pointercancel', stop)
  }

  // The listeners live on the window: every move redraws the decoration, so the handle element
  // itself is replaced and would lose the drag after the first step.
  window.addEventListener('pointermove', move)
  window.addEventListener('pointerup', stop)
  window.addEventListener('pointercancel', stop)
}

function boundaryHandle(view: EditorView, side: 'start' | 'end'): HTMLElement {
  const handle = document.createElement('span')
  handle.className = `nectrix-occurrence-handle nectrix-occurrence-handle-${side}`
  handle.contentEditable = 'false'
  handle.setAttribute('role', 'button')
  handle.setAttribute('aria-label', occurrenceHandleStrings[side])
  handle.title = occurrenceHandleStrings.description
  handle.addEventListener('pointerdown', (event) => startBoundaryDrag(view, side, event))
  return handle
}
