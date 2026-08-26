// SPDX-License-Identifier: AGPL-3.0-or-later

import type { JSONContent } from '@tiptap/core'

export interface DocumentSummary {
  id: string
  title: string
  revision: number
  createdAt: string
  updatedAt: string
}

export interface DocumentRecord extends DocumentSummary {
  documentJson: JSONContent
  plainText: string
}

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

export async function listDocuments(): Promise<DocumentSummary[]> {
  const payload = await request<{ documents: DocumentSummary[] }>('/api/documents')
  return payload.documents
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
): Promise<DocumentRecord> {
  const payload = await request<{ document: DocumentRecord }>(`/api/documents/${encodeURIComponent(document.id)}`, {
    method: 'PUT',
    body: JSON.stringify({
      baseRevision: document.revision,
      title,
      documentJson,
    }),
  })
  return payload.document
}
