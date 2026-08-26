// SPDX-License-Identifier: AGPL-3.0-or-later

import { readFile } from 'node:fs/promises'

const allowedLicenses = new Set([
  'Apache-2.0',
  'BSD-2-Clause',
  'BSD-3-Clause',
  'BlueOak-1.0.0',
  'CC0-1.0',
  'ISC',
  'MIT',
  'MIT-0',
  'MPL-2.0',
])

const lock = JSON.parse(await readFile(new URL('../package-lock.json', import.meta.url), 'utf8'))
const counts = new Map()
const rejected = []

for (const [path, metadata] of Object.entries(lock.packages)) {
  if (!path.startsWith('node_modules/')) continue
  const name = path.replace(/^node_modules\//, '')
  const license = metadata.license
  if (typeof license !== 'string' || !allowedLicenses.has(license)) {
    rejected.push(`${name}: ${license ?? 'licenza mancante'}`)
    continue
  }
  counts.set(license, (counts.get(license) ?? 0) + 1)
}

if (rejected.length > 0) {
  console.error('Dipendenze con licenza assente o non approvata:')
  for (const item of rejected) console.error(`- ${item}`)
  process.exit(1)
}

console.log('Licenze verificate nel package-lock.json:')
for (const [license, count] of [...counts].sort(([left], [right]) => left.localeCompare(right))) {
  console.log(`- ${license}: ${count}`)
}
