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
): Promise<DocumentSummary[]> {
  const query = new URLSearchParams({ scope })
  if (contextId !== null) {
    query.set('contextId', contextId)
    query.set('contextMode', contextMode)
  }
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
): Promise<DocumentRecord> {
  const payload = await request<{ document: DocumentRecord }>(`/api/documents/${encodeURIComponent(document.id)}`, {
    method: 'PUT',
    body: JSON.stringify({
      baseRevision: document.revision,
      title,
      documentJson,
      occurrenceCreates,
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
export async function deleteContext(id: string): Promise<void> {
  await request<{ deleted: boolean }>(`/api/contexts/${encodeURIComponent(id)}`, { method: 'DELETE' })
}

/** Concept and Entity reached through Context → Document → KnowledgeOccurrence. */
export async function contextKnowledgeObjects(
  id: string,
  mode: ContextMode,
): Promise<{ id: string; object_type: 'concept' | 'entity'; name: string }[]> {
  const payload = await request<{ objects: { id: string; object_type: 'concept' | 'entity'; name: string }[] }>(
    `/api/contexts/${encodeURIComponent(id)}/knowledge-objects?mode=${mode}`,
  )
  return payload.objects
}

export async function assignDocumentContext(documentId: string, contextId: string | null): Promise<DocumentRecord> {
  const payload = await request<{ document: DocumentRecord }>(
    `/api/documents/${encodeURIComponent(documentId)}/context`,
    { method: 'POST', body: JSON.stringify({ contextId }) },
  )
  return payload.document
}
