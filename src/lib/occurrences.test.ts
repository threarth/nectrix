// SPDX-License-Identifier: AGPL-3.0-or-later

import type { JSONContent } from '@tiptap/core'
import { describe, expect, test } from 'vitest'
import {
  canKeepCutOccurrenceIds,
  collectContextOccurrences,
  collectOccurrenceTexts,
  collectOccurrences,
  deriveContextCreates,
  deriveOccurrenceCreates,
  occurrenceFingerprint,
  parseCutClipboardPayload,
  planOccurrenceIdRewrite,
  type CutToken,
  type PendingKnowledgeObject,
  validateOccurrences,
} from './occurrences'

/** Deterministic canonical UUIDv7 values, distinguished by the last four hex digits. */
function uuid(suffix: string): string {
  return `0192a1b2-c3d4-7e5f-8a9b-0c1d2e3f${suffix}`
}

const OCCURRENCE_A = uuid('0a01')
const OCCURRENCE_B = uuid('0b01')
const CONCEPT = uuid('c001')
const ENTITY = uuid('e001')
const DOCUMENT_ID = uuid('d001')

function marked(text: string, occurrenceId: string, knowledgeObjectId = CONCEPT, objectType = 'concept'): JSONContent {
  return {
    type: 'text',
    marks: [{ type: 'knowledgeOccurrence', attrs: { occurrenceId, knowledgeObjectId, objectType } }],
    text,
  }
}

function paragraph(...content: JSONContent[]): JSONContent {
  return { type: 'paragraph', content }
}

function doc(...content: JSONContent[]): JSONContent {
  return { type: 'doc', content }
}

describe('lettura delle occurrence dal documento', () => {
  test('raccoglie le occurrence anche dentro liste e citazioni', () => {
    const document = doc(
      paragraph(marked('Backlog', OCCURRENCE_A)),
      {
        type: 'bulletList',
        content: [{ type: 'listItem', content: [paragraph(marked('Rocket Lab', OCCURRENCE_B, ENTITY, 'entity'))] }],
      },
    )

    const occurrences = collectOccurrences(document)
    expect([...occurrences.keys()]).toEqual([OCCURRENCE_A, OCCURRENCE_B])
    expect(occurrences.get(OCCURRENCE_B)).toEqual({
      occurrenceId: OCCURRENCE_B,
      knowledgeObjectId: ENTITY,
      objectType: 'entity',
    })
  })

  test('accetta frammenti contigui dello stesso ID nello stesso textblock', () => {
    const document = doc(paragraph(
      marked('Rocket', OCCURRENCE_A),
      { type: 'text', marks: [{ type: 'bold' }, { type: 'knowledgeOccurrence', attrs: { occurrenceId: OCCURRENCE_A, knowledgeObjectId: CONCEPT, objectType: 'concept' } }], text: ' Lab' },
    ))

    expect(validateOccurrences(document)).toEqual([])
    expect(collectOccurrences(document).size).toBe(1)
  })

  test('rifiuta lo stesso ID in due intervalli disgiunti dello stesso paragrafo', () => {
    const document = doc(paragraph(
      marked('primo', OCCURRENCE_A),
      { type: 'text', text: ' testo non marcato ' },
      marked('secondo', OCCURRENCE_A),
    ))

    expect(validateOccurrences(document)).toEqual([{ code: 'occurrence_split', occurrenceId: OCCURRENCE_A }])
  })

  test('rifiuta lo stesso ID distribuito su due textblock', () => {
    const document = doc(paragraph(marked('primo', OCCURRENCE_A)), paragraph(marked('secondo', OCCURRENCE_A)))

    expect(validateOccurrences(document)).toEqual([{ code: 'occurrence_split', occurrenceId: OCCURRENCE_A }])
  })

  test('rifiuta lo stesso ID associato a KnowledgeObject differenti', () => {
    const document = doc(paragraph(marked('primo', OCCURRENCE_A)), paragraph(marked('secondo', OCCURRENCE_A, ENTITY, 'entity')))

    expect(validateOccurrences(document)).toContainEqual({ code: 'occurrence_conflict', occurrenceId: OCCURRENCE_A })
  })

  test('segnala un mark occurrence con attributi non validi senza raccoglierlo', () => {
    const document = doc(paragraph({
      type: 'text',
      marks: [{ type: 'knowledgeOccurrence', attrs: { occurrenceId: 'non-un-uuid', knowledgeObjectId: CONCEPT, objectType: 'concept' } }],
      text: 'manipolato',
    }))

    expect(validateOccurrences(document)).toEqual([{ code: 'occurrence_invalid', occurrenceId: '' }])
    expect(collectOccurrences(document).size).toBe(0)
  })
})

describe('derivazione dei create al salvataggio', () => {
  const pending = new Map<string, PendingKnowledgeObject>([[CONCEPT, { objectType: 'concept', name: 'Backlog' }]])

  test('non dichiara nulla per le occurrence già persistite', () => {
    const document = doc(paragraph(marked('Backlog', OCCURRENCE_A)))

    expect(deriveOccurrenceCreates(document, new Set([OCCURRENCE_A]), pending)).toEqual([])
  })

  test('dichiara il KnowledgeObject nuovo una sola volta, sulla prima occurrence', () => {
    const document = doc(paragraph(marked('Backlog', OCCURRENCE_A)), paragraph(marked('Backlog', OCCURRENCE_B)))

    expect(deriveOccurrenceCreates(document, new Set(), pending)).toEqual([
      { occurrenceId: OCCURRENCE_A, knowledgeObjectId: CONCEPT, objectType: 'concept', newObject: true, name: 'Backlog' },
      { occurrenceId: OCCURRENCE_B, knowledgeObjectId: CONCEPT, objectType: 'concept', newObject: false },
    ])
  })

  test('dichiara la Entity nuova con il proprio EntityType', () => {
    const entityPending = new Map<string, PendingKnowledgeObject>([
      [ENTITY, { objectType: 'entity', name: 'Rocket Lab USA', entityTypeId: uuid('7001') }],
    ])
    const document = doc(paragraph(marked('Rocket Lab', OCCURRENCE_A, ENTITY, 'entity')))

    expect(deriveOccurrenceCreates(document, new Set(), entityPending)).toEqual([
      {
        occurrenceId: OCCURRENCE_A,
        knowledgeObjectId: ENTITY,
        objectType: 'entity',
        newObject: true,
        name: 'Rocket Lab USA',
        entityTypeId: uuid('7001'),
      },
    ])
  })

  test('associa un KnowledgeObject esistente senza dichiararne la creazione', () => {
    const document = doc(paragraph(marked('Backlog', OCCURRENCE_A)))

    expect(deriveOccurrenceCreates(document, new Set(), new Map())).toEqual([
      { occurrenceId: OCCURRENCE_A, knowledgeObjectId: CONCEPT, objectType: 'concept', newObject: false },
    ])
  })

  test('non dichiara nulla quando undo ha rimosso il mark appena creato', () => {
    const document = doc(paragraph({ type: 'text', text: 'Backlog' }))

    expect(deriveOccurrenceCreates(document, new Set(), pending)).toEqual([])
  })
})

describe('policy prudente di cut/paste', () => {
  const fingerprint = occurrenceFingerprint('Backlog', [OCCURRENCE_A])

  function token(overrides: Partial<CutToken> = {}): CutToken {
    return {
      nonce: 'nonce-1',
      documentId: DOCUMENT_ID,
      fingerprint,
      occurrenceIds: [OCCURRENCE_A],
      consumed: false,
      ...overrides,
    }
  }

  function check(overrides: Record<string, unknown> = {}) {
    return {
      token: token(),
      payload: { nonce: 'nonce-1', documentId: DOCUMENT_ID, fingerprint },
      documentId: DOCUMENT_ID,
      fingerprint,
      pastedIds: [OCCURRENCE_A],
      presentIds: new Set<string>(),
      ...overrides,
    }
  }

  test('conserva gli ID di un taglio verificato nello stesso documento', () => {
    expect(canKeepCutOccurrenceIds(check())).toBe(true)
  })

  test('rigenera gli ID senza formato clipboard Nectrix', () => {
    expect(canKeepCutOccurrenceIds(check({ payload: null }))).toBe(false)
  })

  test('rigenera gli ID con token già consumato', () => {
    expect(canKeepCutOccurrenceIds(check({ token: token({ consumed: true }) }))).toBe(false)
  })

  test('rigenera gli ID in un documento differente', () => {
    expect(canKeepCutOccurrenceIds(check({ documentId: uuid('d002') }))).toBe(false)
  })

  test('rigenera gli ID se il payload non coincide con il taglio', () => {
    expect(canKeepCutOccurrenceIds(check({ fingerprint: occurrenceFingerprint('altro', [OCCURRENCE_A]) }))).toBe(false)
  })

  test('rigenera gli ID se l originale è ancora presente nel documento', () => {
    expect(canKeepCutOccurrenceIds(check({ presentIds: new Set([OCCURRENCE_A]) }))).toBe(false)
  })

  test('rigenera gli ID se lo stesso ID produrrebbe più intervalli', () => {
    expect(canKeepCutOccurrenceIds(check({ pastedIds: [OCCURRENCE_A, OCCURRENCE_A] }))).toBe(false)
  })

  test('legge il payload clipboard solo se ben formato', () => {
    expect(parseCutClipboardPayload(null)).toBeNull()
    expect(parseCutClipboardPayload('non json')).toBeNull()
    expect(parseCutClipboardPayload('{"nonce":"n"}')).toBeNull()
    expect(parseCutClipboardPayload(JSON.stringify({ nonce: 'n', documentId: DOCUMENT_ID, fingerprint: 'abc' }))).toEqual({
      nonce: 'n',
      documentId: DOCUMENT_ID,
      fingerprint: 'abc',
    })
  })

  test('assegna un solo nuovo ID a ciascun ID copiato', () => {
    let counter = 0
    const runs = [
      { occurrenceId: OCCURRENCE_A, knowledgeObjectId: CONCEPT, objectType: 'concept' as const },
      { occurrenceId: OCCURRENCE_B, knowledgeObjectId: ENTITY, objectType: 'entity' as const },
      { occurrenceId: OCCURRENCE_A, knowledgeObjectId: CONCEPT, objectType: 'concept' as const },
    ]

    const mapping = planOccurrenceIdRewrite(runs, () => uuid(`f00${(counter += 1)}`))
    expect(mapping.size).toBe(2)
    expect(mapping.get(OCCURRENCE_A)).toBe(uuid('f001'))
    expect(mapping.get(OCCURRENCE_B)).toBe(uuid('f002'))
  })
})

describe('testo corrente di una occurrence', () => {
  test('unisce i frammenti contigui della stessa occurrence', () => {
    const document = doc(paragraph(
      marked('Rocket', OCCURRENCE_A),
      { type: 'text', marks: [{ type: 'bold' }, { type: 'knowledgeOccurrence', attrs: { occurrenceId: OCCURRENCE_A, knowledgeObjectId: CONCEPT, objectType: 'concept' } }], text: ' Lab' },
      { type: 'text', text: ' resta fuori' },
    ))

    expect(collectOccurrenceTexts(document).get(OCCURRENCE_A)).toBe('Rocket Lab')
  })

  test('non elenca una occurrence il cui mark non è più nel contenuto', () => {
    const texts = collectOccurrenceTexts(doc(paragraph({ type: 'text', text: 'niente' })))
    expect(texts.has(OCCURRENCE_A)).toBe(false)
  })
})

describe('FASE 14.1: il Context marca frammenti, non documenti', () => {
  const contextId = '01900000-0000-7000-8000-000000000001'
  const rangeId = '01900000-0000-7000-8000-000000000002'

  /** A range that crosses two paragraphs keeps one identity: the thought does not end with the paragraph. */
  const acrossParagraphs = {
    type: 'doc',
    content: [
      {
        type: 'paragraph',
        content: [{
          type: 'text',
          text: 'Prima parte',
          marks: [{ type: 'contextOccurrence', attrs: { occurrenceId: rangeId, contextId } }],
        }],
      },
      {
        type: 'paragraph',
        content: [{
          type: 'text',
          text: 'seconda parte',
          marks: [{ type: 'contextOccurrence', attrs: { occurrenceId: rangeId, contextId } }],
        }],
      },
    ],
  }

  test('lo stesso range attraverso due paragrafi resta una sola occurrence', () => {
    const found = collectContextOccurrences(acrossParagraphs)

    expect([...found.keys()]).toEqual([rangeId])
    expect(found.get(rangeId)).toEqual({ occurrenceId: rangeId, contextId })
  })

  test('un mark con attributi incompleti non diventa un range', () => {
    const broken = {
      type: 'doc',
      content: [{
        type: 'paragraph',
        content: [{ type: 'text', text: 'Rotto', marks: [{ type: 'contextOccurrence', attrs: { contextId } }] }],
      }],
    }

    expect(collectContextOccurrences(broken).size).toBe(0)
  })

  test('solo i range che il server non conosce vengono dichiarati al salvataggio', () => {
    expect(deriveContextCreates(acrossParagraphs, new Set())).toEqual([{ occurrenceId: rangeId, contextId }])
    expect(deriveContextCreates(acrossParagraphs, new Set([rangeId]))).toEqual([])
  })
})
