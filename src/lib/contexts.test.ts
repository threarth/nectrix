// SPDX-License-Identifier: AGPL-3.0-or-later

import { describe, expect, test } from 'vitest'
import {
  contextPathLabel,
  deletionBlockers,
  orderContexts,
  possibleParents,
  type ContextNode,
} from './contexts'

const tree: ContextNode[] = [
  { id: 'uni', parent_id: null, name: 'Università' },
  { id: 'psi', parent_id: 'uni', name: 'Psicologia' },
  { id: 'jung', parent_id: 'psi', name: 'Junghiana' },
  { id: 'lavoro', parent_id: null, name: 'Lavoro' },
]

describe('gerarchia dei Context', () => {
  test('appiattisce in profondità, con il genitore sempre prima dei figli', () => {
    const rows = orderContexts(tree)

    expect(rows.map((row) => row.id)).toEqual(['uni', 'psi', 'jung', 'lavoro'])
    expect(rows.map((row) => row.depth)).toEqual([0, 1, 2, 0])
  })

  test('deriva il percorso dalla gerarchia', () => {
    const rows = orderContexts(tree)

    expect(contextPathLabel(rows[2])).toBe('Università / Psicologia / Junghiana')
    expect(contextPathLabel(rows[3])).toBe('Lavoro')
  })

  test('esclude sé stesso e i propri discendenti dai genitori possibili', () => {
    const rows = orderContexts(tree)

    expect(possibleParents(rows, 'psi').map((row) => row.id)).toEqual(['uni', 'lavoro'])
    expect(possibleParents(rows, 'uni').map((row) => row.id)).toEqual(['lavoro'])
  })

  test('un elenco vuoto non produce righe', () => {
    expect(orderContexts([])).toEqual([])
  })
})

describe('impedimenti alla cancellazione di un Context', () => {
  test('un contesto con sotto-contesti non si elimina', () => {
    const rows = orderContexts(tree)

    expect(deletionBlockers(rows, 'uni')).toEqual({ children: 1, documents: 0 })
    expect(deletionBlockers(rows, 'psi')).toEqual({ children: 1, documents: 0 })
  })

  test('un contesto con documenti non si elimina', () => {
    const rows = orderContexts([{ id: 'solo', parent_id: null, name: 'Solo', documents: 3 }])

    expect(deletionBlockers(rows, 'solo')).toEqual({ children: 0, documents: 3 })
  })

  test('una foglia senza documenti si elimina', () => {
    const rows = orderContexts(tree)

    expect(deletionBlockers(rows, 'jung')).toBeNull()
    expect(deletionBlockers(rows, 'lavoro')).toBeNull()
  })
})
