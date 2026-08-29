// SPDX-License-Identifier: AGPL-3.0-or-later

import { describe, expect, test } from 'vitest'
import {
  contextPathLabel,
  deletionBlockers,
  deletionImpact,
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

    expect(deletionBlockers(rows, 'uni')).toEqual({ children: 1 })
    expect(deletionBlockers(rows, 'psi')).toEqual({ children: 1 })
  })

  test('i frammenti marcati non impediscono la cancellazione, la spiegano', () => {
    const rows = orderContexts([{ id: 'solo', parent_id: null, name: 'Solo', occurrences: 3 }])

    expect(deletionBlockers(rows, 'solo')).toBeNull()
    expect(deletionImpact(rows, 'solo')).toBe(3)
  })

  test('una foglia si elimina e dichiara che non toglie nulla dal testo', () => {
    const rows = orderContexts(tree)

    expect(deletionBlockers(rows, 'jung')).toBeNull()
    expect(deletionBlockers(rows, 'lavoro')).toBeNull()
    expect(deletionImpact(rows, 'jung')).toBe(0)
  })
})
