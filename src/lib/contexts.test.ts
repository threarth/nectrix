// SPDX-License-Identifier: AGPL-3.0-or-later

import { describe, expect, test } from 'vitest'
import { contextPathLabel, orderContexts, possibleParents, type ContextNode } from './contexts'

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
