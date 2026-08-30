// SPDX-License-Identifier: AGPL-3.0-or-later

export interface ContextNode {
  id: string
  parent_id: string | null
  name: string
  /** Document holding at least one range of this Context, not counting the descendants. */
  documents?: number
  /** Text ranges currently marked with this Context. */
  occurrences?: number
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

/**
 * Why a Context cannot be deleted yet, or null when nothing blocks it. Only the sub-context block
 * it: the ranges are removed from the text by the deletion itself, so marked fragments are a
 * consequence to declare, not an impediment.
 */
export function deletionBlockers(rows: readonly ContextRow[], contextId: string): { children: number } | null {
  const row = rows.find((candidate) => candidate.id === contextId)
  if (row === undefined) return null
  const children = rows.filter((candidate) => candidate.parent_id === contextId).length
  return children === 0 ? null : { children }
}

/** What deleting the Context would take away from the text, so the command can say it first. */
export function deletionImpact(rows: readonly ContextRow[], contextId: string): number {
  return rows.find((candidate) => candidate.id === contextId)?.occurrences ?? 0
}

export interface ContextObjectRow {
  context_id: string
  id: string
  object_type: 'concept' | 'entity'
  name: string
}

export type TreeRow =
  | { kind: 'context'; id: string; name: string; depth: number; occurrences: number; hasChildren: boolean }
  | { kind: 'object'; id: string; name: string; depth: number; objectType: 'concept' | 'entity'; contextId: string }

/**
 * Flattens the hierarchy into the rows the sidebar draws: every Context is followed by the
 * knowledge its own ranges contain, then by its sub-context. A collapsed Context hides everything
 * below it — its knowledge included — because that is what the fold means to the eye.
 */
export function treeRows(
  contexts: readonly ContextRow[],
  objects: readonly ContextObjectRow[],
  collapsed: ReadonlySet<string>,
): TreeRow[] {
  const byParent = new Map<string, ContextRow[]>()
  for (const context of [...contexts].sort((first, second) => first.name.localeCompare(second.name))) {
    const key = context.parent_id ?? ''
    byParent.set(key, [...(byParent.get(key) ?? []), context])
  }
  const objectsOf = new Map<string, ContextObjectRow[]>()
  for (const object of objects) {
    objectsOf.set(object.context_id, [...(objectsOf.get(object.context_id) ?? []), object])
  }

  const rows: TreeRow[] = []
  const walk = (parentId: string, depth: number): void => {
    for (const context of byParent.get(parentId) ?? []) {
      const children = byParent.get(context.id) ?? []
      const own = objectsOf.get(context.id) ?? []
      rows.push({
        kind: 'context',
        id: context.id,
        name: context.name,
        depth,
        occurrences: context.occurrences ?? 0,
        hasChildren: children.length > 0 || own.length > 0,
      })
      if (collapsed.has(context.id)) continue
      for (const object of own) {
        rows.push({
          kind: 'object',
          id: object.id,
          name: object.name,
          depth: depth + 1,
          objectType: object.object_type,
          contextId: context.id,
        })
      }
      walk(context.id, depth + 1)
    }
  }
  walk('', 0)
  return rows
}
