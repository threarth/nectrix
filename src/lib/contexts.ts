// SPDX-License-Identifier: AGPL-3.0-or-later

export interface ContextNode {
  id: string
  parent_id: string | null
  name: string
  /** Document assigned directly to this Context, not counting the descendants. */
  documents?: number
}

export interface ContextRow extends ContextNode {
  /** Distance from the root, used to indent the tree. */
  depth: number
  /** Names from the root down to this Context, for breadcrumbs and flat selects. */
  path: string[]
}

const PATH_SEPARATOR = ' / '

/**
 * Flattens the hierarchy depth first, so a parent always precedes its children. Depth and path are
 * derived here and never persisted: the database stores only parent_id.
 */
export function orderContexts(contexts: readonly ContextNode[]): ContextRow[] {
  const children = new Map<string, ContextNode[]>()
  for (const context of contexts) {
    const key = context.parent_id ?? ''
    children.set(key, [...(children.get(key) ?? []), context])
  }

  const rows: ContextRow[] = []
  const visit = (parentKey: string, depth: number, path: string[]): void => {
    for (const context of children.get(parentKey) ?? []) {
      const contextPath = [...path, context.name]
      rows.push({ ...context, depth, path: contextPath })
      visit(context.id, depth + 1, contextPath)
    }
  }
  visit('', 0, [])
  return rows
}

export function contextPathLabel(row: ContextRow): string {
  return row.path.join(PATH_SEPARATOR)
}

/** Contexts that may become the parent of the given one: never itself, never its descendants. */
export function possibleParents(rows: readonly ContextRow[], contextId: string): ContextRow[] {
  const excluded = new Set<string>([contextId])
  for (const row of rows) {
    if (row.parent_id !== null && excluded.has(row.parent_id)) excluded.add(row.id)
  }
  return rows.filter((row) => !excluded.has(row.id))
}

/** Why a Context cannot be deleted yet, or null when nothing blocks it. */
export function deletionBlockers(rows: readonly ContextRow[], contextId: string): { children: number; documents: number } | null {
  const row = rows.find((candidate) => candidate.id === contextId)
  if (row === undefined) return null
  const children = rows.filter((candidate) => candidate.parent_id === contextId).length
  const documents = row.documents ?? 0
  return children === 0 && documents === 0 ? null : { children, documents }
}
