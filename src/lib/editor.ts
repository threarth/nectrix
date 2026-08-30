// SPDX-License-Identifier: AGPL-3.0-or-later

import { Extension, Mark, Node, type Editor } from '@tiptap/core'
import { AllSelection, Plugin, PluginKey, TextSelection, type EditorState } from '@tiptap/pm/state'
import { Decoration, DecorationSet, type EditorView } from '@tiptap/pm/view'
import type { Mark as ProseMirrorMark, Slice } from '@tiptap/pm/model'
import { StarterKit } from '@tiptap/starter-kit'
import {
  canKeepCutOccurrenceIds,
  collectOccurrences,
  collectSliceOccurrenceRuns,
  collectSliceReferences,
  CUT_CLIPBOARD_FORMAT,
  REFERENCE_NODES,
  isOccurrenceAttributes,
  parseCutClipboardPayload,
  planOccurrenceIdRewrite,
  remapSliceOccurrences,
  sliceFingerprint,
  type CutClipboardPayload,
  type CutToken,
  type OccurrenceAttributes,
  type ReferenceNodeName,
  type SliceReference,
} from './occurrences'
import { isUuidV7 } from './uuid'
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
    return ['mark', { ...HTMLAttributes, class: 'chaorganix-highlight' }, 0]
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

const CONTEXT_DATA_ATTRIBUTES = {
  occurrenceId: 'data-context-occurrence-id',
  contextId: 'data-context-id',
} as const

function contextAttributeSpec(name: keyof typeof CONTEXT_DATA_ATTRIBUTES) {
  const attribute = CONTEXT_DATA_ATTRIBUTES[name]
  return {
    default: null,
    parseHTML: (element: HTMLElement) => element.getAttribute(attribute),
    renderHTML: (attributes: Record<string, unknown>) => ({ [attribute]: attributes[name] }),
  }
}

/**
 * A Context drawn around a fragment. Unlike a Concept or an Entity it may cross paragraphs: a
 * thought rarely stops where a paragraph does, and the Context is what gives the fragment its
 * meaning. The Document knows nothing about it — the mark lives on the text.
 */
const ContextOccurrence = Mark.create({
  name: 'contextOccurrence',
  inclusive: false,

  addAttributes() {
    return {
      occurrenceId: contextAttributeSpec('occurrenceId'),
      contextId: contextAttributeSpec('contextId'),
    }
  },

  parseHTML() {
    return [{
      tag: `span[${CONTEXT_DATA_ATTRIBUTES.occurrenceId}]`,
      getAttrs: (element: HTMLElement) => {
        const occurrenceId = element.getAttribute(CONTEXT_DATA_ATTRIBUTES.occurrenceId)
        const contextId = element.getAttribute(CONTEXT_DATA_ATTRIBUTES.contextId)
        return isUuidV7(occurrenceId) && isUuidV7(contextId) ? { occurrenceId, contextId } : false
      },
    }]
  },

  renderHTML({ HTMLAttributes }) {
    return ['span', { ...HTMLAttributes, class: 'chaorganix-context-occurrence' }, 0]
  },
})

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
    return ['span', { ...HTMLAttributes, class: 'chaorganix-knowledge-occurrence' }, 0]
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
            // La key rende il widget riconoscibile fra un ridisegno e l'altro: senza, l'elemento
            // verrebbe ricreato a ogni transazione e sparirebbe da sotto il puntatore.
            return DecorationSet.create(state.doc, [
              Decoration.widget(range.from, (view) => boundaryHandle(view, 'start'), {
                side: -1, key: 'occurrence-handle-start', stopEvent: () => true,
              }),
              Decoration.widget(range.to, (view) => boundaryHandle(view, 'end'), {
                side: 1, key: 'occurrence-handle-end', stopEvent: () => true,
              }),
            ])
          },
        },
      }),
    ]
  },
})

/**
 * Editorial references to an Entity or to one of its SemanticBlock. They are pointers, not copies:
 * the node keeps its own referenceId and the ID of the destination, never the name, the Template
 * or the values, which stay authoritative in the database and are resolved when rendering.
 */
const REFERENCE_ATTRIBUTES = {
  entityReference: { destination: 'entityId', dataset: 'data-entity-id' },
  semanticBlockReference: { destination: 'semanticBlockId', dataset: 'data-semantic-block-id' },
} as const

function referenceNode(name: keyof typeof REFERENCE_ATTRIBUTES) {
  const { destination, dataset } = REFERENCE_ATTRIBUTES[name]
  const tag = `span[data-reference-kind="${name}"]`

  return Node.create({
    name,
    inline: true,
    group: 'inline',
    atom: true,
    selectable: true,

    addAttributes() {
      return {
        referenceId: {
          default: null,
          parseHTML: (element: HTMLElement) => element.getAttribute('data-reference-id'),
          renderHTML: (attributes: Record<string, unknown>) => ({ 'data-reference-id': attributes.referenceId }),
        },
        [destination]: {
          default: null,
          parseHTML: (element: HTMLElement) => element.getAttribute(dataset),
          renderHTML: (attributes: Record<string, unknown>) => ({ [dataset]: attributes[destination] }),
        },
      }
    },

    parseHTML() {
      return [{
        tag,
        // A manipulated fragment never becomes a reference: both IDs must be canonical.
        getAttrs: (element: HTMLElement) => {
          const referenceId = element.getAttribute('data-reference-id')
          const target = element.getAttribute(dataset)
          return isUuidV7(referenceId) && isUuidV7(target)
            ? { referenceId, [destination]: target }
            : false
        },
      }]
    },

    renderHTML({ HTMLAttributes }) {
      return ['span', { ...HTMLAttributes, 'data-reference-kind': name, class: `chaorganix-reference chaorganix-${name}` }]
    },
  })
}

const EntityReference = referenceNode('entityReference')
const SemanticBlockReference = referenceNode('semanticBlockReference')

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
  ContextOccurrence,
  EntityReference,
  SemanticBlockReference,
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
 * A copy always produces new IDs, only a cut proved by the custom Chaorganix format inside the same
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
      occurrenceIds: [
        ...collectSliceOccurrenceRuns(slice).map((run) => run.occurrenceId),
        ...collectSliceReferences(slice).map((reference) => reference.referenceId),
      ],
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
    const references = collectSliceReferences(slice)
    const payload = this.pastedPayload
    this.pastedPayload = null
    if (runs.length === 0 && references.length === 0) return slice

    if (this.canKeepIds(slice, runs, references, payload, view)) {
      if (this.cutToken !== null) this.cutToken = { ...this.cutToken, consumed: true }
      this.options.onPaste(uniqueByOccurrenceId(runs))
      return slice
    }

    // Copy/paste rigenera l'identita della collocazione, non la destinazione: un riferimento
    // incollato punta ancora alla stessa Entity o allo stesso SemanticBlock.
    const mapping = planOccurrenceIdRewrite(runs, this.options.createId)
    for (const reference of references) {
      if (!mapping.has(reference.referenceId)) mapping.set(reference.referenceId, this.options.createId())
    }
    const pasted = runs.map((run) => ({ ...run, occurrenceId: mapping.get(run.occurrenceId) ?? run.occurrenceId }))
    this.options.onPaste(uniqueByOccurrenceId(pasted))
    return remapSliceOccurrences(slice, mapping)
  }

  private canKeepIds(
    slice: Slice,
    runs: readonly OccurrenceAttributes[],
    references: readonly SliceReference[],
    payload: CutClipboardPayload | null,
    view: EditorView,
  ): boolean {
    return canKeepCutOccurrenceIds({
      token: this.cutToken,
      payload,
      documentId: this.options.documentId,
      fingerprint: sliceFingerprint(slice),
      pastedIds: [...runs.map((run) => run.occurrenceId), ...references.map((reference) => reference.referenceId)],
      presentIds: new Set([
        ...collectOccurrences(view.state.doc.toJSON()).keys(),
        ...documentReferenceIds(view.state),
      ]),
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

export interface ReferenceLabelOptions {
  /** Label of a destination, resolved outside the document and never stored in it. */
  label: (node: ReferenceNodeName, destinationId: string) => string | undefined
  /** Shown while the label is unknown or the destination cannot be resolved. */
  fallback: string
}

/**
 * Shows the label of each editorial reference as a decoration. The document keeps only the IDs:
 * what you read is resolved at render time, so renaming the destination is immediately visible.
 */
export function referenceLabelsExtension(options: ReferenceLabelOptions): Extension {
  return Extension.create({
    name: 'referenceLabels',

    addProseMirrorPlugins() {
      return [
        new Plugin({
          key: new PluginKey('referenceLabels'),
          props: {
            decorations(state) {
              const decorations: Decoration[] = []
              state.doc.descendants((node, position) => {
                const name = node.type.name as ReferenceNodeName
                if (!REFERENCE_NODES.includes(name)) return true
                const destination = name === 'entityReference'
                  ? node.attrs.entityId
                  : node.attrs.semanticBlockId
                const label = options.label(name, String(destination)) ?? options.fallback
                decorations.push(Decoration.node(position, position + node.nodeSize, { 'data-label': label }))
                return true
              })
              return DecorationSet.create(state.doc, decorations)
            },
          },
        }),
      ]
    },
  })
}

/** Destinations referenced by the content, so the client can resolve their labels. */
export function referenceDestinations(state: EditorState): { entities: string[]; blocks: string[] } {
  const entities: string[] = []
  const blocks: string[] = []
  state.doc.descendants((node) => {
    if (node.type.name === 'entityReference' && typeof node.attrs.entityId === 'string') entities.push(node.attrs.entityId)
    if (node.type.name === 'semanticBlockReference' && typeof node.attrs.semanticBlockId === 'string') blocks.push(node.attrs.semanticBlockId)
    return true
  })
  return { entities: [...new Set(entities)], blocks: [...new Set(blocks)] }
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

export interface DocumentRange {
  from: number
  to: number
}

/**
 * Parts of a range that carry no occurrence: the only places a Highlight may go. A Concept or an
 * Entity already has its own visual identity, so highlighting it would only add invisible state.
 */
export function occurrenceFreeRanges(state: EditorState, from: number, to: number): DocumentRange[] {
  const markType = state.schema.marks.knowledgeOccurrence
  const ranges: DocumentRange[] = []
  let cursor = from

  state.doc.nodesBetween(from, to, (node, position) => {
    if (!node.isText || !node.marks.some((mark) => mark.type === markType)) return true
    const start = Math.max(position, from)
    const end = Math.min(position + node.nodeSize, to)
    if (start > cursor) ranges.push({ from: cursor, to: start })
    cursor = Math.max(cursor, end)
    return true
  })

  if (cursor < to) ranges.push({ from: cursor, to })
  return ranges
}

/** referenceId already placed in the document: a paste must not duplicate one. */
function documentReferenceIds(state: EditorState): string[] {
  const ids: string[] = []
  state.doc.descendants((node) => {
    if (typeof node.attrs.referenceId === 'string') ids.push(node.attrs.referenceId)
    return true
  })
  return ids
}

/** One text node of a textblock, with the occurrence mark it carries. */
interface OccurrenceFragment {
  from: number
  to: number
  mark: ProseMirrorMark | null
}

/**
 * Occurrence range containing a position, or null when the position carries no occurrence.
 * The same occurrenceId can cover several adjacent text nodes, for example when only part of it is
 * highlighted: the range covers the whole run, whichever fragment the position falls in.
 */
export function occurrenceRangeAt(state: EditorState, position: number): OccurrenceRange | null {
  const markType = state.schema.marks.knowledgeOccurrence
  const resolved = state.doc.resolve(position)
  if (!resolved.parent.isTextblock) return null

  const blockStart = resolved.start()
  const fragments: OccurrenceFragment[] = []
  resolved.parent.forEach((child, offset) => {
    const from = blockStart + offset
    fragments.push({ from, to: from + child.nodeSize, mark: child.marks.find((mark) => mark.type === markType) ?? null })
  })

  const index = fragments.findIndex((fragment) =>
    fragment.mark !== null && position >= fragment.from && position <= fragment.to)
  if (index === -1) return null

  const mark = fragments[index].mark
  if (mark === null) return null
  let first = index
  let last = index
  while (first > 0 && continues(fragments[first - 1], fragments[first], mark)) first -= 1
  while (last < fragments.length - 1 && continues(fragments[last], fragments[last + 1], mark)) last += 1

  return { from: fragments[first].from, to: fragments[last].to, mark, blockStart, blockEnd: resolved.end() }
}

/**
 * True when two adjacent fragments both belong to the run. Marks are compared by occurrenceId:
 * two text nodes of the same occurrence carry equal but distinct mark instances.
 */
function continues(left: OccurrenceFragment, right: OccurrenceFragment, mark: ProseMirrorMark): boolean {
  if (left.to !== right.from) return false
  return belongsTo(left.mark, mark) && belongsTo(right.mark, mark)
}

function belongsTo(candidate: ProseMirrorMark | null, mark: ProseMirrorMark): boolean {
  return candidate !== null && candidate.attrs.occurrenceId === mark.attrs.occurrenceId
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
  handle.className = `chaorganix-occurrence-handle chaorganix-occurrence-handle-${side}`
  handle.contentEditable = 'false'
  handle.setAttribute('role', 'button')
  handle.setAttribute('aria-label', occurrenceHandleStrings[side])
  handle.title = occurrenceHandleStrings.description
  handle.addEventListener('pointerdown', (event) => startBoundaryDrag(view, side, event))
  return handle
}
