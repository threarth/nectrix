// SPDX-License-Identifier: AGPL-3.0-or-later

export function uuidV7(): string {
  const bytes = crypto.getRandomValues(new Uint8Array(16))
  let milliseconds = Date.now()
  for (let index = 5; index >= 0; index -= 1) {
    bytes[index] = milliseconds & 0xff
    milliseconds = Math.floor(milliseconds / 256)
  }
  bytes[6] = (bytes[6] & 0x0f) | 0x70
  bytes[8] = (bytes[8] & 0x3f) | 0x80
  const hex = [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('')
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}

const uuidV7Pattern = /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

/** Same canonical lowercase UUIDv7 shape accepted by the API (Nectrix\UuidV7::isValid). */
export function isUuidV7(value: unknown): value is string {
  return typeof value === 'string' && uuidV7Pattern.test(value)
}
