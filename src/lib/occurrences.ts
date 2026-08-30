// SPDX-License-Identifier: AGPL-3.0-or-later

import type { JSONContent } from '@tiptap/core'
import { Fragment, Slice, type Mark, type Node as ProseMirrorNode } from '@tiptap/pm/model'
import type { OccurrenceCreate } from './api'
import { isUuidV7 } from './uuid'

export const OCCURRENCE_MARK_NAME = 'knowledgeOccurrence'

/** Custom clipboard format: only this proves an internal cut, plain HTML never does. */
export const CUT_CLIPBOARD_FORMAT = 'application/x-chaorganix-slice'

export type KnowledgeObjectType = 'concept' | 'entity'

export interface OccurrenceAttributes {
  occurrenceId: string
  knowledgeObjectId: string
  objectType: KnowledgeObjectType
}

/** A KnowledgeObject created in the editing session and not yet persisted. */
export interface PendingKnowledgeObject {
  objectType: KnowledgeObjectType
  name: string
  entityTypeId?: string
}

export type OccurrenceProblemCode = 'occurrence_split' | 'occurrence_conflict' | 'occurrence_invalid'

export interface OccurrenceProblem {
  code: OccurrenceProblemCode
  occurrenceId: string
}

/** True when the value carries the three complete attributes required by INV-OCC-03. */
export function isOccurrenceAttributes(value: unknown): value is OccurrenceAttributes {
  if (typeof value !== 'object' || value === null) return false
  const attributes = value as Record<string, unknown>
  if (!isUuidV7(attributes.occurrenceId) || !isUuidV7(attributes.knowledgeObjectId)) return false
  return attributes.objectType === 'concept' || attributes.objectType === 'entity'
}

function hasOccurrenceMark(node: JSONContent): boolean {
  return (node.marks ?? []).some((mark) => mark.type === OCCURRENCE_MARK_NAME)
}

function occurrenceAttributesOf(node: JSONContent): OccurrenceAttributes | null {
  for (const mark of node.marks ?? []) {
    if (mark.type !== OCCURRENCE_MARK_NAME) continue
    if (!isOccurrenceAttributes(mark.attrs)) return null
    const { occurrenceId, knowledgeObjectId, objectType } = mark.attrs as OccurrenceAttributes
    return { occurrenceId, knowledgeObjectId, objectType }
  }
  return null
}

/**
 * Visits every inline container, that is every node whose direct children are text nodes.
 * Works both on a whole document and on a bare clipboard fragment of inline content.
 */
function visitInlineContainers(node: JSONContent, visit: (children: JSONContent[]) => void): void {
  const content = node.content ?? []
  if (content.some((child) => child.type === 'text')) {
    visit(content)
    return
  }
  for (const child of content) visitInlineContainers(child, visit)
}

interface OccurrenceScan {
  occurrences: Map<string, OccurrenceAttributes>
  problems: OccurrenceProblem[]
}

function sameAttributes(left: OccurrenceAttributes, right: OccurrenceAttributes): boolean {
  return left.knowledgeObjectId === right.knowledgeObjectId && left.objectType === right.objectType
}

/**
 * Scans occurrence runs in document order. A run is a group of adjacent text nodes sharing the
 * same occurrenceId inside one inline container: reopening a closed run breaks INV-OCC-05.
 */
function scanOccurrences(root: JSONContent): OccurrenceScan {
  const occurrences = new Map<string, OccurrenceAttributes>()
  const problems: OccurrenceProblem[] = []
  const closed = new Set<string>()

  visitInlineContainers(root, (children) => {
    let openId: string | null = null
    for (const child of children) {
      const attributes = occurrenceAttributesOf(child)
      if (attributes === null && hasOccurrenceMark(child)) problems.push({ code: 'occurrence_invalid', occurrenceId: '' })
      const id = attributes?.occurrenceId ?? null
      if (id !== openId) {
        if (openId !== null) closed.add(openId)
        openId = id
      }
      if (attributes === null || id === null) continue
      if (closed.has(id)) problems.push({ code: 'occurrence_split', occurrenceId: id })
      const known = occurrences.get(id)
      if (known !== undefined && !sameAttributes(known, attributes)) {
        problems.push({ code: 'occurrence_conflict', occurrenceId: id })
      }
      occurrences.set(id, attributes)
    }
    if (openId !== null) closed.add(openId)
  })

  return { occurrences, problems }
}

/** Every occurrence present in the content, keyed by occurrenceId, in document order. */
export function collectOccurrences(root: JSONContent): Map<string, OccurrenceAttributes> {
  return scanOccurrences(root).occurrences
}

/** INV-OCC-05 and INV-OCC-03 violations detectable on the client, empty when the content is sound. */
export function validateOccurrences(root: JSONContent): OccurrenceProblem[] {
  return scanOccurrences(root).problems
}

/**
 * Current text of every occurrence in the content, read live in a single walk. Mirrors what the API
 * extracts from the saved revision, so an editor draft can show the text it has instead of the
 * persisted one, without re-reading the document once per occurrence.
 */
export function collectOccurrenceTexts(root: JSONContent): Map<string, string> {
  const texts = new Map<string, string>()
  visitInlineContainers(root, (children) => {
    for (const child of children) {
      const id = occurrenceAttributesOf(child)?.occurrenceId
      if (id === undefined) continue
      texts.set(id, (texts.get(id) ?? '') + (child.text ?? ''))
    }
  })
  return texts
}

/** Occurrence runs of a clipboard slice, one entry per contiguous run, in slice order. */
export function collectSliceOccurrenceRuns(slice: Slice): OccurrenceAttributes[] {
  const runs: OccurrenceAttributes[] = []
  const fragment = slice.content.toJSON() as JSONContent[] | null
  visitInlineContainers({ type: 'doc', content: fragment ?? [] }, (children) => {
    let openId: string | null = null
    for (const child of children) {
      const attributes = occurrenceAttributesOf(child)
      const id = attributes?.occurrenceId ?? null
      if (id !== openId) {
        openId = id
        if (attributes !== null) runs.push(attributes)
      }
    }
  })
  return runs
}

export const REFERENCE_NODES = ['entityReference', 'semanticBlockReference'] as const

export type ReferenceNodeName = (typeof REFERENCE_NODES)[number]

export interface SliceReference {
  node: ReferenceNodeName
  referenceId: string
}

/** Editorial references carried by a clipboard slice, in slice order. */
export function collectSliceReferences(slice: Slice): SliceReference[] {
  const references: SliceReference[] = []
  const visit = (nodes: JSONContent[]): void => {
    for (const node of nodes) {
      const name = node.type as ReferenceNodeName
      if (REFERENCE_NODES.includes(name) && typeof node.attrs?.referenceId === 'string') {
        references.push({ node: name, referenceId: node.attrs.referenceId })
        continue
      }
      visit(node.content ?? [])
    }
  }
  visit((slice.content.toJSON() as JSONContent[] | null) ?? [])
  return references
}

const FNV_OFFSET_BASIS = 0x811c9dc5
const FNV_PRIME = 0x01000193
const FINGERPRINT_HEX_LENGTH = 8
const BLOCK_SEPARATOR = '\n'

/**
 * Payload fingerprint used to prove that a paste carries exactly the slice that was cut.
 * Built only from what survives the clipboard round trip: visible text and occurrence IDs.
 */
export function occurrenceFingerprint(text: string, occurrenceIds: readonly string[]): string {
  const payload = `${text}|${[...occurrenceIds].sort().join(',')}`
  let hash = FNV_OFFSET_BASIS
  for (let index = 0; index < payload.length; index += 1) {
    hash ^= payload.charCodeAt(index)
    hash = Math.imul(hash, FNV_PRIME) >>> 0
  }
  return hash.toString(16).padStart(FINGERPRINT_HEX_LENGTH, '0')
}

/** Fingerprint of a clipboard slice, computed identically at cut time and at paste time. */
export function sliceFingerprint(slice: Slice): string {
  const text = slice.content.textBetween(0, slice.content.size, BLOCK_SEPARATOR, '')
  const identities = [
    ...collectSliceOccurrenceRuns(slice).map((run) => run.occurrenceId),
    ...collectSliceReferences(slice).map((reference) => reference.referenceId),
  ]
  return occurrenceFingerprint(text, identities)
}

/** What travels in the custom clipboard format. It is a claim, never a proof on its own. */
export interface CutClipboardPayload {
  nonce: string
  documentId: string
  fingerprint: string
}

/** What the editor remembers in memory about its own last cut. Never persisted. */
export interface CutToken extends CutClipboardPayload {
  occurrenceIds: readonly string[]
  consumed: boolean
}

export function parseCutClipboardPayload(raw: string | null): CutClipboardPayload | null {
  if (raw === null || raw === '') return null
  try {
    const parsed: unknown = JSON.parse(raw)
    if (typeof parsed !== 'object' || parsed === null) return null
    const payload = parsed as Record<string, unknown>
    if (typeof payload.nonce !== 'string' || typeof payload.documentId !== 'string') return null
    if (typeof payload.fingerprint !== 'string') return null
    return { nonce: payload.nonce, documentId: payload.documentId, fingerprint: payload.fingerprint }
  } catch (cause) {
    console.warn('Formato clipboard Chaorganix non leggibile, il paste userà nuovi ID.', cause)
    return null
  }
}

export interface CutPasteCheck {
  token: CutToken | null
  payload: CutClipboardPayload | null
  documentId: string
  fingerprint: string
  pastedIds: readonly string[]
  presentIds: ReadonlySet<string>
}

/**
 * INV-OCC-11: a paste keeps the original IDs only for a verified cut inside the same Document.
 * Any unproven or ambiguous case is treated as a copy and gets new IDs.
 */
export function canKeepCutOccurrenceIds(check: CutPasteCheck): boolean {
  const { token, payload } = check
  if (token === null || payload === null || token.consumed) return false
  if (token.nonce !== payload.nonce) return false
  if (token.documentId !== check.documentId || payload.documentId !== check.documentId) return false
  if (token.fingerprint !== check.fingerprint || payload.fingerprint !== check.fingerprint) return false
  if (new Set(check.pastedIds).size !== check.pastedIds.length) return false
  if (check.pastedIds.some((id) => check.presentIds.has(id))) return false
  const cutIds = new Set(token.occurrenceIds)
  return check.pastedIds.every((id) => cutIds.has(id))
}

/**
 * INV-OCC-10 and INV-OCC-14: one new ID per old ID, so contiguous fragments of the same copied
 * occurrence stay a single occurrence, while knowledgeObjectId and objectType are preserved.
 */
export function planOccurrenceIdRewrite(
  runs: readonly OccurrenceAttributes[],
  nextId: () => string,
): Map<string, string> {
  const mapping = new Map<string, string>()
  for (const run of runs) {
    if (!mapping.has(run.occurrenceId)) mapping.set(run.occurrenceId, nextId())
  }
  return mapping
}

function remapMarks(marks: readonly Mark[], mapping: ReadonlyMap<string, string>): Mark[] {
  return marks.map((mark) => {
    if (mark.type.name !== OCCURRENCE_MARK_NAME) return mark
    const rewritten = mapping.get(mark.attrs.occurrenceId as string)
    if (rewritten === undefined) return mark
    return mark.type.create({ ...mark.attrs, occurrenceId: rewritten })
  })
}

function remapFragment(fragment: Fragment, mapping: ReadonlyMap<string, string>): Fragment {
  const nodes: ProseMirrorNode[] = []
  fragment.forEach((node) => {
    const marks = remapMarks(node.marks, mapping)
    if (node.isText) {
      nodes.push(node.mark(marks))
      return
    }
    const rewritten = REFERENCE_NODES.includes(node.type.name as ReferenceNodeName)
      ? mapping.get(node.attrs.referenceId as string)
      : undefined
    const attrs = rewritten === undefined ? node.attrs : { ...node.attrs, referenceId: rewritten }
    nodes.push(node.type.create(attrs, remapFragment(node.content, mapping), marks))
  })
  return Fragment.fromArray(nodes)
}

/**
 * Rewrites the identities carried by a clipboard slice: occurrenceId of the marks and referenceId
 * of the editorial references. The destinations are never touched.
 */
export function remapSliceOccurrences(slice: Slice, mapping: ReadonlyMap<string, string>): Slice {
  if (mapping.size === 0) return slice
  return new Slice(remapFragment(slice.content, mapping), slice.openStart, slice.openEnd)
}

/**
 * Builds the occurrence creation list for a save from the marks actually present in the document.
 * Undone creations disappear on their own and a pasted occurrence declares its own record, while
 * the KnowledgeObject is created once by the first occurrence that references it.
 */
export function deriveOccurrenceCreates(
  root: JSONContent,
  persistedOccurrenceIds: ReadonlySet<string>,
  pendingObjects: ReadonlyMap<string, PendingKnowledgeObject>,
): OccurrenceCreate[] {
  const creates: OccurrenceCreate[] = []
  const declaredObjects = new Set<string>()

  for (const attributes of collectOccurrences(root).values()) {
    if (persistedOccurrenceIds.has(attributes.occurrenceId)) continue
    const pending = pendingObjects.get(attributes.knowledgeObjectId)
    const isNewObject = pending !== undefined
      && pending.objectType === attributes.objectType
      && !declaredObjects.has(attributes.knowledgeObjectId)
    const create: OccurrenceCreate = {
      occurrenceId: attributes.occurrenceId,
      knowledgeObjectId: attributes.knowledgeObjectId,
      objectType: attributes.objectType,
      newObject: isNewObject,
    }
    if (isNewObject && pending !== undefined) {
      create.name = pending.name
      if (pending.entityTypeId !== undefined) create.entityTypeId = pending.entityTypeId
      declaredObjects.add(attributes.knowledgeObjectId)
    }
    creates.push(create)
  }

  return creates
}

export const CONTEXT_MARK_NAME = 'contextOccurrence'

export interface ContextOccurrenceAttributes {
  occurrenceId: string
  contextId: string
}

/** True when the value carries the two complete attributes a Context range requires. */
export function isContextAttributes(value: unknown): value is ContextOccurrenceAttributes {
  if (typeof value !== 'object' || value === null) return false
  const attributes = value as Record<string, unknown>
  return isUuidV7(attributes.occurrenceId) && isUuidV7(attributes.contextId)
}

/**
 * Context ranges present in the draft, keyed by occurrenceId. A range may span several paragraphs,
 * so the same identity legitimately appears on text nodes of different blocks.
 */
export function collectContextOccurrences(root: JSONContent): Map<string, ContextOccurrenceAttributes> {
  const found = new Map<string, ContextOccurrenceAttributes>()
  const visit = (node: JSONContent): void => {
    for (const mark of node.marks ?? []) {
      if (mark.type !== CONTEXT_MARK_NAME || !isContextAttributes(mark.attrs)) continue
      const { occurrenceId, contextId } = mark.attrs as ContextOccurrenceAttributes
      found.set(occurrenceId, { occurrenceId, contextId })
    }
    for (const child of node.content ?? []) visit(child)
  }
  visit(root)
  return found
}

/** Ranges the server does not know yet: exactly what the save has to declare. */
export function deriveContextCreates(
  root: JSONContent,
  persistedOccurrenceIds: ReadonlySet<string>,
): ContextOccurrenceAttributes[] {
  const creates: ContextOccurrenceAttributes[] = []
  for (const attributes of collectContextOccurrences(root).values()) {
    if (persistedOccurrenceIds.has(attributes.occurrenceId)) continue
    creates.push(attributes)
  }
  return creates
}
