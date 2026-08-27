// SPDX-License-Identifier: AGPL-3.0-or-later

import { defaultHighlightColors, normalizeHighlightColor } from './editor'

export const MIN_HIGHLIGHT_COLORS = 4
export const MAX_HIGHLIGHT_COLORS = 10
export const HIGHLIGHT_PALETTE_STORAGE_KEY = 'nectrix.highlightPalette.v1'

export function readHighlightPalette(storage: Storage | undefined = browserStorage()): string[] {
  if (!storage) return [...defaultHighlightColors]

  try {
    const value: unknown = JSON.parse(storage.getItem(HIGHLIGHT_PALETTE_STORAGE_KEY) ?? 'null')
    if (!isPalette(value)) return [...defaultHighlightColors]
    return value.map(normalizeHighlightColor)
  } catch {
    return [...defaultHighlightColors]
  }
}

export function writeHighlightPalette(colors: string[], storage: Storage | undefined = browserStorage()): void {
  if (!storage || !isPalette(colors)) return
  storage.setItem(HIGHLIGHT_PALETTE_STORAGE_KEY, JSON.stringify(colors.map(normalizeHighlightColor)))
}

function isPalette(value: unknown): value is string[] {
  return Array.isArray(value)
    && value.length >= MIN_HIGHLIGHT_COLORS
    && value.length <= MAX_HIGHLIGHT_COLORS
    && value.every((color) => /^#[0-9a-f]{6}$/i.test(color))
}

function browserStorage(): Storage | undefined {
  return typeof window === 'undefined' ? undefined : window.localStorage
}
