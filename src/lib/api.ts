// SPDX-License-Identifier: AGPL-3.0-or-later

import type { JSONContent } from '@tiptap/core'
import type { ContextNode } from './contexts'

export type DocumentStatus = 'active' | 'archived' | 'trashed'

export type ContextMode = 'exact' | 'subtree'

export interface DocumentSummary {
  id: string
  title: string
  revision: number
  status: DocumentStatus
  contextId: string | null
  createdAt: string
  updatedAt: string
}

export interface DocumentRecord extends DocumentSummary {
  documentJson: JSONContent
  plainText: string
}

export interface OccurrenceCreate {
  occurrenceId: string
  knowledgeObjectId: string
  objectType: 'concept' | 'entity'
  newObject: boolean
  name?: string
  entityTypeId?: string
}

/** Existence and discriminator of a KnowledgeObject, as returned by the resolution endpoint. */
export interface KnowledgeObjectRef { id: string; object_type: 'concept' | 'entity' }

export interface EntityType { id: string; name: string; description: string | null; status: 'active' | 'archived' }

/** One occurrence as shown by an inspector: the text always comes from the Document content. */
export interface KnowledgeOccurrenceView {
  id: string
  documentId: string
  documentTitle: string
  status: 'active' | 'detached' | 'deleted'
  text: string
}

export interface ConceptAliasView { id: string; alias: string }

export interface EntityIdentifierView {
  id: string
  scheme: string
  value: string
  normalized_value: string
  authority_or_namespace: string | null
  normalization_version: number
}

export interface EntityIdentifierInput {
  scheme: string
  value: string
  authorityOrNamespace?: string
}

/** Another Entity already declaring the same normalised identity. Never merged automatically. */
export interface DuplicateCandidate { id: string; name: string }

export interface KnowledgeObjectDetail {
  id: string
  objectType: 'concept' | 'entity'
  name: string
  description: string | null
  status: 'active' | 'orphan' | 'archived'
  entityType: { id: string; name: string; status: 'active' | 'archived' } | null
  aliases: ConceptAliasView[]
  identifiers: EntityIdentifierView[]
  occurrences: KnowledgeOccurrenceView[]
}
export interface KnowledgeSearchResult { id: string; object_type: 'concept' | 'entity'; name: string; entity_type_name: string | null }

interface ApiErrorPayload {
  error?: {
    code?: string
    message?: string
    details?: Record<string, unknown>
  }
}

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly code: string,
    message: string,
    public readonly details: Record<string, unknown> = {},
  ) {
    super(message)
  }
}

async function request<T>(path: string, init?: RequestInit): Promise<T> {
  const response = await fetch(path, {
    ...init,
    headers: {
      Accept: 'application/json',
      ...(init?.body ? { 'Content-Type': 'application/json' } : {}),
      ...init?.headers,
    },
  })
  const payload = (await response.json()) as T & ApiErrorPayload
  if (!response.ok) {
    throw new ApiError(
      response.status,
      payload.error?.code ?? 'unknown_error',
      payload.error?.message ?? 'La richiesta non è riuscita.',
      payload.error?.details,
    )
  }
  return payload
}

/** Archived and trashed Documents are returned only when the scope asks for them. */
export async function listDocuments(
  scope: DocumentStatus = 'active',
  contextId: string | null = null,
  contextMode: ContextMode = 'subtree',
  tagIds: string[] = [],
): Promise<DocumentSummary[]> {
  const query = new URLSearchParams({ scope })
  if (contextId !== null) {
    query.set('contextId', contextId)
    query.set('contextMode', contextMode)
  }
  if (tagIds.length > 0) query.set('tagIds', tagIds.join(','))
  const payload = await request<{ documents: DocumentSummary[] }>(`/api/documents?${query.toString()}`)
  return payload.documents
}

/** Archive, trash and restore are reversible and never remove content. */
export async function setDocumentLifecycle(
  id: string,
  action: 'archive' | 'trash' | 'restore',
): Promise<DocumentRecord> {
  const payload = await request<{ document: DocumentRecord }>(
    `/api/documents/${encodeURIComponent(id)}/${action}`,
    { method: 'POST' },
  )
  return payload.document
}

export async function getDocument(id: string): Promise<DocumentRecord> {
  const payload = await request<{ document: DocumentRecord }>(`/api/documents/${encodeURIComponent(id)}`)
  return payload.document
}

export async function createDocument(title = 'Documento senza titolo'): Promise<DocumentRecord> {
  const payload = await request<{ document: DocumentRecord }>('/api/documents', {
    method: 'POST',
    body: JSON.stringify({ title }),
  })
  return payload.document
}

export async function saveDocument(
  document: Pick<DocumentRecord, 'id' | 'revision'>,
  title: string,
  documentJson: JSONContent,
  occurrenceCreates: OccurrenceCreate[] = [],
  contextOccurrenceCreates: { occurrenceId: string; contextId: string }[] = [],
): Promise<DocumentRecord> {
  const payload = await request<{ document: DocumentRecord }>(`/api/documents/${encodeURIComponent(document.id)}`, {
    method: 'PUT',
    body: JSON.stringify({
      baseRevision: document.revision,
      title,
      documentJson,
      occurrenceCreates,
      contextOccurrenceCreates,
    }),
  })
  return payload.document
}

export async function createEntityType(name: string): Promise<EntityType> {
  const payload = await request<{ entityType: EntityType }>('/api/entity-types', {
    method: 'POST', body: JSON.stringify({ name }),
  })
  return payload.entityType
}

export async function searchKnowledge(query: string): Promise<KnowledgeSearchResult[]> {
  const payload = await request<{ results: KnowledgeSearchResult[] }>(`/api/knowledge/search?q=${encodeURIComponent(query)}`)
  return payload.results
}

/** Resolves pasted KnowledgeObject IDs: unknown IDs are simply missing from the answer. */
export async function resolveKnowledgeObjects(ids: string[]): Promise<KnowledgeObjectRef[]> {
  if (ids.length === 0) return []
  const payload = await request<{ objects: KnowledgeObjectRef[] }>(
    `/api/knowledge-objects?ids=${encodeURIComponent(ids.join(','))}`,
  )
  return payload.objects
}

export async function getKnowledgeObject(id: string): Promise<KnowledgeObjectDetail> {
  const payload = await request<{ object: KnowledgeObjectDetail }>(`/api/knowledge-objects/${encodeURIComponent(id)}`)
  return payload.object
}

/** Archive and restore are explicit and never delete: they only change the lifecycle state. */
export async function setKnowledgeObjectArchived(id: string, archived: boolean): Promise<KnowledgeObjectDetail> {
  const action = archived ? 'archive' : 'restore'
  const payload = await request<{ object: KnowledgeObjectDetail }>(
    `/api/knowledge-objects/${encodeURIComponent(id)}/${action}`,
    { method: 'POST' },
  )
  return payload.object
}

export async function setEntityTypeArchived(id: string, archived: boolean): Promise<EntityType> {
  const action = archived ? 'archive' : 'restore'
  const payload = await request<{ entityType: EntityType }>(
    `/api/entity-types/${encodeURIComponent(id)}/${action}`,
    { method: 'POST' },
  )
  return payload.entityType
}

export async function listEntityTypes(): Promise<EntityType[]> {
  const payload = await request<{ entityTypes: EntityType[] }>('/api/entity-types')
  return payload.entityTypes
}

export async function addConceptAlias(objectId: string, alias: string): Promise<KnowledgeObjectDetail> {
  const payload = await request<{ object: KnowledgeObjectDetail }>(
    `/api/knowledge-objects/${encodeURIComponent(objectId)}/aliases`,
    { method: 'POST', body: JSON.stringify({ alias }) },
  )
  return payload.object
}

export async function removeConceptAlias(aliasId: string): Promise<KnowledgeObjectDetail> {
  const payload = await request<{ object: KnowledgeObjectDetail }>(
    `/api/concept-aliases/${encodeURIComponent(aliasId)}`,
    { method: 'DELETE' },
  )
  return payload.object
}

export async function addEntityIdentifier(
  objectId: string,
  input: EntityIdentifierInput,
): Promise<{ object: KnowledgeObjectDetail; duplicateCandidates: DuplicateCandidate[] }> {
  return request<{ object: KnowledgeObjectDetail; duplicateCandidates: DuplicateCandidate[] }>(
    `/api/knowledge-objects/${encodeURIComponent(objectId)}/identifiers`,
    { method: 'POST', body: JSON.stringify(input) },
  )
}

export async function removeEntityIdentifier(identifierId: string): Promise<KnowledgeObjectDetail> {
  const payload = await request<{ object: KnowledgeObjectDetail }>(
    `/api/entity-identifiers/${encodeURIComponent(identifierId)}`,
    { method: 'DELETE' },
  )
  return payload.object
}

/** Renames a Concept or an Entity and rewrites its description. Nothing else changes. */
export async function updateKnowledgeObject(
  id: string,
  input: { name: string; description: string | null },
): Promise<KnowledgeObjectDetail> {
  const payload = await request<{ object: KnowledgeObjectDetail }>(
    `/api/knowledge-objects/${encodeURIComponent(id)}`,
    { method: 'PUT', body: JSON.stringify(input) },
  )
  return payload.object
}

export type SearchCategory = 'document' | 'concept' | 'entity' | 'entity_type' | 'context' | 'tag'
export type SearchMatch = 'full_text' | 'name' | 'alias' | 'identifier' | 'identity'

export interface SearchResult {
  category: SearchCategory
  /** How the result was reached: a string match, a declared name, or occurrence identity. */
  match: SearchMatch
  id: string
  label: string
  detail: string | null
  status?: string
  documentId?: string
  occurrenceId?: string
  objectId?: string
  objectType?: 'concept' | 'entity'
  contextId?: string
  tagId?: string
}

export async function search(query: string): Promise<SearchResult[]> {
  const payload = await request<{ results: SearchResult[] }>(`/api/search?q=${encodeURIComponent(query)}`)
  return payload.results
}

/** Documents holding an active occurrence of the object: identity, not words. */
export async function searchByObject(objectId: string): Promise<SearchResult[]> {
  const payload = await request<{ results: SearchResult[] }>(`/api/search?objectId=${encodeURIComponent(objectId)}`)
  return payload.results
}

export interface TemplateField {
  id: string
  template_id: string
  name: string
  field_type: string
  is_required: number
  sort_order: number
  options_json: string | null
}

export interface Template {
  id: string
  name: string
  description: string | null
  status: 'active' | 'archived'
  fields: TemplateField[]
}

export interface BlockFieldValue {
  id: string
  ordinal: number
  value: unknown
  origin: string
}

export interface BlockField {
  fieldId: string
  name: string
  fieldType: string
  required: boolean
  options: string[]
  values: BlockFieldValue[]
}

export interface SemanticBlock {
  id: string
  templateId: string
  templateName: string | null
  sortOrder: number
  fields: BlockField[]
}

export async function listTemplates(): Promise<Template[]> {
  const payload = await request<{ templates: Template[] }>('/api/templates')
  return payload.templates
}

export async function createTemplate(name: string): Promise<Template> {
  const payload = await request<{ template: Template }>('/api/templates', {
    method: 'POST',
    body: JSON.stringify({ name }),
  })
  return payload.template
}

export async function addTemplateField(
  templateId: string,
  input: { name: string; fieldType: string; required?: boolean; options?: string[] },
): Promise<Template> {
  const payload = await request<{ template: Template }>(
    `/api/templates/${encodeURIComponent(templateId)}/fields`,
    { method: 'POST', body: JSON.stringify(input) },
  )
  return payload.template
}

export async function listBlocks(objectId: string): Promise<SemanticBlock[]> {
  const payload = await request<{ blocks: SemanticBlock[] }>(
    `/api/knowledge-objects/${encodeURIComponent(objectId)}/blocks`,
  )
  return payload.blocks
}

export async function addBlock(objectId: string, templateId: string): Promise<SemanticBlock[]> {
  const payload = await request<{ blocks: SemanticBlock[] }>(
    `/api/knowledge-objects/${encodeURIComponent(objectId)}/blocks`,
    { method: 'POST', body: JSON.stringify({ templateId }) },
  )
  return payload.blocks
}

export async function removeBlock(blockId: string): Promise<SemanticBlock[]> {
  const payload = await request<{ blocks: SemanticBlock[] }>(
    `/api/semantic-blocks/${encodeURIComponent(blockId)}`,
    { method: 'DELETE' },
  )
  return payload.blocks
}

/** Replaces the values of one field: a single field takes one value, a multi field a list. */
export async function setBlockValues(
  blockId: string,
  fieldId: string,
  values: unknown[],
): Promise<SemanticBlock[]> {
  const payload = await request<{ blocks: SemanticBlock[] }>(
    `/api/semantic-blocks/${encodeURIComponent(blockId)}/values`,
    { method: 'POST', body: JSON.stringify({ fieldId, values }) },
  )
  return payload.blocks
}

export interface RelationView {
  id: string
  relationType: string
  description: string | null
  direction: 'outgoing' | 'incoming'
  otherId: string
  otherType: 'concept' | 'entity'
  otherName: string
}

export async function listRelations(objectId: string): Promise<RelationView[]> {
  const payload = await request<{ relations: RelationView[] }>(
    `/api/knowledge-objects/${encodeURIComponent(objectId)}/relations`,
  )
  return payload.relations
}

/** Direction is part of the identity: the inverse arc is a different relation. */
export async function createRelation(
  objectId: string,
  input: { targetId: string; relationType: string; description?: string },
): Promise<RelationView[]> {
  const payload = await request<{ relations: RelationView[] }>(
    `/api/knowledge-objects/${encodeURIComponent(objectId)}/relations`,
    { method: 'POST', body: JSON.stringify(input) },
  )
  return payload.relations
}

export async function deleteRelation(objectId: string, relationId: string): Promise<RelationView[]> {
  const payload = await request<{ relations: RelationView[] }>(
    `/api/knowledge-objects/${encodeURIComponent(objectId)}/relations/${encodeURIComponent(relationId)}`,
    { method: 'DELETE' },
  )
  return payload.relations
}

/** Suggested predicates plus the ones already used: never a closed list. */
export async function listRelationTypes(): Promise<string[]> {
  const payload = await request<{ types: string[] }>('/api/relation-types')
  return payload.types
}

export interface ComparisonRow {
  label: string
  /** Where the row comes from: persisted data, a derived path or a typed value. */
  path: 'persisted' | 'derived' | 'field_value'
  cells: string[][]
}

export interface Comparison {
  mode: 'concepts' | 'entities'
  subjects: { id: string; name: string }[]
  rows: ComparisonRow[]
}

/** Concept and Entity are compared separately: they are not the same kind of thing. */
export async function compareObjects(objectIds: string[]): Promise<Comparison> {
  return request<Comparison>('/api/compare', { method: 'POST', body: JSON.stringify({ objectIds }) })
}

export interface EvidenceView {
  id: string
  family: 'document' | 'occurrence' | 'semantic_block' | 'field_value'
  destination_id: string
  label: string
  detail: string | null
  state: string
  document_id: string | null
  note: string | null
}

export async function listRelationEvidence(relationId: string): Promise<EvidenceView[]> {
  const payload = await request<{ evidence: EvidenceView[] }>(
    `/api/relations/${encodeURIComponent(relationId)}/evidence`,
  )
  return payload.evidence
}

/** Evidence points only at data that already exists and is verified before being written. */
export async function addRelationEvidence(
  relationId: string,
  input: { family: EvidenceView['family']; destinationId: string; note?: string },
): Promise<EvidenceView[]> {
  const payload = await request<{ evidence: EvidenceView[] }>(
    `/api/relations/${encodeURIComponent(relationId)}/evidence`,
    { method: 'POST', body: JSON.stringify(input) },
  )
  return payload.evidence
}

export async function removeRelationEvidence(
  relationId: string,
  family: EvidenceView['family'],
  evidenceId: string,
): Promise<EvidenceView[]> {
  const payload = await request<{ evidence: EvidenceView[] }>(
    `/api/relations/${encodeURIComponent(relationId)}/evidence/${encodeURIComponent(family)}/${encodeURIComponent(evidenceId)}`,
    { method: 'DELETE' },
  )
  return payload.evidence
}

export interface StructuredFilter {
  fieldId: string
  operator: string
  value?: unknown
}

export interface StructuredMatch {
  path: 'field_value'
  template: string | null
  field: string
  fieldType: string
  operator: string
}

export interface StructuredEntity {
  id: string
  name: string
  entityTypeName: string | null
  matches: StructuredMatch[]
  documents: { path: 'occurrence'; id: string; title: string }[]
}

/** Typed comparisons on FieldValue, combinable with Context and Tag through the occurrence. */
export async function structuredSearch(
  filters: StructuredFilter[],
  editorial: { contextId?: string | null; contextMode?: ContextMode; tagIds?: string[] } = {},
): Promise<{ entities: StructuredEntity[]; counts: { entities: number; documents: number } }> {
  return request<{ entities: StructuredEntity[]; counts: { entities: number; documents: number } }>(
    '/api/search/structured',
    {
      method: 'POST',
      body: JSON.stringify({
        filters,
        contextId: editorial.contextId ?? '',
        contextMode: editorial.contextMode ?? 'subtree',
        tagIds: (editorial.tagIds ?? []).join(','),
      }),
    },
  )
}

export interface ResolvedReference { id: string; label: string; detail: string | null }

/** Labels of the destinations of the editorial references, derived and never stored. */
export async function resolveReferences(
  entities: string[],
  blocks: string[],
): Promise<{ entities: ResolvedReference[]; semanticBlocks: ResolvedReference[] }> {
  if (entities.length === 0 && blocks.length === 0) return { entities: [], semanticBlocks: [] }
  const query = new URLSearchParams()
  if (entities.length > 0) query.set('entities', entities.join(','))
  if (blocks.length > 0) query.set('blocks', blocks.join(','))
  return request<{ entities: ResolvedReference[]; semanticBlocks: ResolvedReference[] }>(
    `/api/references?${query.toString()}`,
  )
}

export interface Tag { id: string; name: string }
export interface TagSummary extends Tag { documents: number }

export async function listTags(): Promise<TagSummary[]> {
  const payload = await request<{ tags: TagSummary[] }>('/api/tags')
  return payload.tags
}

export async function createTag(name: string): Promise<Tag> {
  const payload = await request<{ tag: Tag }>('/api/tags', { method: 'POST', body: JSON.stringify({ name }) })
  return payload.tag
}

export async function renameTag(id: string, name: string): Promise<Tag> {
  const payload = await request<{ tag: Tag }>(`/api/tags/${encodeURIComponent(id)}`, {
    method: 'PUT',
    body: JSON.stringify({ name }),
  })
  return payload.tag
}

/** Refused while the Tag is still assigned to a Document. */
export async function deleteTag(id: string): Promise<void> {
  await request<{ deleted: boolean }>(`/api/tags/${encodeURIComponent(id)}`, { method: 'DELETE' })
}

export async function listDocumentTags(documentId: string): Promise<Tag[]> {
  const payload = await request<{ tags: Tag[] }>(`/api/documents/${encodeURIComponent(documentId)}/tags`)
  return payload.tags
}

export async function assignTag(documentId: string, tagId: string): Promise<Tag[]> {
  const payload = await request<{ tags: Tag[] }>(`/api/documents/${encodeURIComponent(documentId)}/tags`, {
    method: 'POST',
    body: JSON.stringify({ tagId }),
  })
  return payload.tags
}

export async function unassignTag(documentId: string, tagId: string): Promise<Tag[]> {
  const payload = await request<{ tags: Tag[] }>(
    `/api/documents/${encodeURIComponent(documentId)}/tags/${encodeURIComponent(tagId)}`,
    { method: 'DELETE' },
  )
  return payload.tags
}

/** Concept and Entity of the Documents selected by the Context and Tag filters. */
export async function derivedKnowledgeObjects(
  contextId: string | null,
  contextMode: ContextMode,
  tagIds: string[],
): Promise<{ id: string; object_type: 'concept' | 'entity'; name: string }[]> {
  const query = new URLSearchParams({ contextMode })
  if (contextId !== null) query.set('contextId', contextId)
  if (tagIds.length > 0) query.set('tagIds', tagIds.join(','))
  const payload = await request<{ objects: { id: string; object_type: 'concept' | 'entity'; name: string }[] }>(
    `/api/knowledge-objects/derived?${query.toString()}`,
  )
  return payload.objects
}

export async function listContexts(): Promise<ContextNode[]> {
  const payload = await request<{ contexts: ContextNode[] }>('/api/contexts')
  return payload.contexts
}

export async function createContext(name: string, parentId: string | null): Promise<ContextNode> {
  const payload = await request<{ context: ContextNode }>('/api/contexts', {
    method: 'POST',
    body: JSON.stringify({ name, parentId }),
  })
  return payload.context
}

export async function renameContext(id: string, name: string): Promise<ContextNode> {
  const payload = await request<{ context: ContextNode }>(`/api/contexts/${encodeURIComponent(id)}`, {
    method: 'PUT',
    body: JSON.stringify({ name }),
  })
  return payload.context
}

/** Moves a whole branch. The API refuses a destination inside the branch itself. */
export async function moveContext(id: string, parentId: string | null): Promise<ContextNode> {
  const payload = await request<{ context: ContextNode }>(`/api/contexts/${encodeURIComponent(id)}/move`, {
    method: 'POST',
    body: JSON.stringify({ parentId }),
  })
  return payload.context
}

/** Refused while the Context still holds sub-context or Document. */
/** Deletes a Context and the ranges that carried it, leaving the text of the Document untouched. */
export async function deleteContext(id: string): Promise<DeletionOutcome> {
  const payload = await request<{ deleted: DeletionOutcome }>(
    `/api/contexts/${encodeURIComponent(id)}`,
    { method: 'DELETE' },
  )
  return payload.deleted
}


export type MatrixAxis = 'concept' | 'entity' | 'entity_type' | 'template'

export interface MatrixCell {
  contextId: string | null
  matches: number
  /** How the count was produced: a cell is never a number without its path. */
  path: 'occurrence' | 'occurrence_entity_type' | 'semantic_block' | 'field_value'
}

export interface MatrixRow {
  id: string
  label: string
  cells: MatrixCell[]
  /** Distinct occurrence of the row, counted once even when several columns show them. */
  total: number
}

export interface MatrixColumn {
  id: string | null
  parentId: string | null
  name: string | null
}

export interface Matrix {
  axis: MatrixAxis
  mode: 'exact' | 'subtree'
  path: MatrixCell['path']
  field: { id: string; name: string; fieldType: string; template: string | null } | null
  columns: MatrixColumn[]
  rows: MatrixRow[]
  truncated: boolean
  counts: { rows: number; contexts: number }
}

export interface MatrixOccurrence {
  occurrenceId: string
  status: string
  objectId: string
  objectType: 'concept' | 'entity'
  documentId: string
  documentTitle: string
  contextId: string | null
  coObjects: { id: string; objectType: 'concept' | 'entity'; label: string }[]
}

export interface MatrixDrill {
  axis: MatrixAxis
  path: MatrixCell['path']
  rowId: string
  contextId: string | null
  occurrences: MatrixOccurrence[]
  counts: { occurrences: number }
}

export interface MatrixQuery {
  axis: MatrixAxis
  mode: 'exact' | 'subtree'
  fieldFilter?: { fieldId: string; operator: string; value?: unknown }
}

/** The matrix of one axis against the Context, counted on the active occurrence. */
export async function fetchMatrix(query: MatrixQuery): Promise<Matrix> {
  return request<Matrix>('/api/matrix', { method: 'POST', body: JSON.stringify(query) })
}

/** The occurrence behind one cell, with the Document that carry them. */
export async function fetchMatrixCell(
  query: MatrixQuery & { rowId: string; contextId: string | null },
): Promise<MatrixDrill> {
  return request<MatrixDrill>('/api/matrix/cell', { method: 'POST', body: JSON.stringify(query) })
}

export interface DeletionOutcome {
  id: string
  objectType?: 'concept' | 'entity'
  /** Ranges the deletion took away, and Document it had to rewrite. */
  occurrences: number
  documents: number
}

/**
 * Deletes a Concept or an Entity for good: its occurrences, what it owned and every mark it left
 * in the text. The words of the user stay where they were written.
 */
export async function deleteKnowledgeObject(objectId: string): Promise<DeletionOutcome> {
  const payload = await request<{ deleted: DeletionOutcome }>(
    `/api/knowledge-objects/${encodeURIComponent(objectId)}`,
    { method: 'DELETE' },
  )
  return payload.deleted
}

export interface IndexSearchResult {
  id: string
  object_type: 'concept' | 'entity' | 'context'
  name: string
  entity_type_name: string | null
  parent_id: string | null
}

/**
 * Concept, Entity and Context in one answer. Marking a fragment is one decision — where it
 * belongs — so the search that serves it is one search.
 */
export async function searchIndex(query: string): Promise<IndexSearchResult[]> {
  const payload = await request<{ results: IndexSearchResult[] }>(
    `/api/index/search?q=${encodeURIComponent(query)}`,
  )
  return payload.results
}

export interface TrashedObject {
  id: string
  object_type?: 'concept' | 'entity'
  name: string
  trashed_at: string
  /** Ranges still marked in the text: what a definitive deletion would take away. */
  occurrences: number
}

export interface TrashContents {
  knowledgeObjects: TrashedObject[]
  contexts: TrashedObject[]
}

/** What sits in the trash of the three organisers, with what each one still holds in the text. */
export async function fetchTrash(): Promise<TrashContents> {
  return request<TrashContents>('/api/trash')
}

/** Moves a Concept or an Entity to the trash: nothing is destroyed, the marks stay in the text. */
export async function trashKnowledgeObject(objectId: string, trashed = true): Promise<void> {
  await request(`/api/knowledge-objects/${encodeURIComponent(objectId)}/${trashed ? 'trash' : 'untrash'}`, {
    method: 'POST',
  })
}

/** Moves a Context to the trash, or brings it back. */
export async function trashContext(contextId: string, trashed = true): Promise<void> {
  await request(`/api/contexts/${encodeURIComponent(contextId)}/${trashed ? 'trash' : 'untrash'}`, {
    method: 'POST',
  })
}
