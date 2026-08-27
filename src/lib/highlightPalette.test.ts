// SPDX-License-Identifier: AGPL-3.0-or-later

import { beforeEach, describe, expect, test } from 'vitest'
import { defaultHighlightColors } from './editor'
import {
  HIGHLIGHT_PALETTE_STORAGE_KEY,
  MAX_HIGHLIGHT_COLORS,
  MIN_HIGHLIGHT_COLORS,
  readHighlightPalette,
  writeHighlightPalette,
} from './highlightPalette'

beforeEach(() => window.localStorage.clear())

describe('palette Highlight locale', () => {
  test('usa dieci colori predefiniti e persiste una palette valida fino a dieci colori', () => {
    expect(readHighlightPalette()).toEqual(defaultHighlightColors)
    expect(readHighlightPalette()).toHaveLength(MAX_HIGHLIGHT_COLORS)

    const palette = ['#101010', '#202020', '#303030', '#404040', '#505050']
    writeHighlightPalette(palette)
    expect(readHighlightPalette()).toEqual(palette)
    expect(readHighlightPalette()).toHaveLength(5)
  })

  test('ignora palette locali fuori dai limiti o con colori non validi', () => {
    window.localStorage.setItem(HIGHLIGHT_PALETTE_STORAGE_KEY, JSON.stringify(['#111111']))
    expect(readHighlightPalette()).toEqual(defaultHighlightColors)

    window.localStorage.setItem(HIGHLIGHT_PALETTE_STORAGE_KEY, JSON.stringify(Array(MAX_HIGHLIGHT_COLORS + 1).fill('#111111')))
    expect(readHighlightPalette()).toEqual(defaultHighlightColors)

    window.localStorage.setItem(HIGHLIGHT_PALETTE_STORAGE_KEY, JSON.stringify(Array(MIN_HIGHLIGHT_COLORS).fill('#invalid')))
    expect(readHighlightPalette()).toEqual(defaultHighlightColors)
  })
})
