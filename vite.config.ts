// SPDX-License-Identifier: AGPL-3.0-or-later

import { svelte } from '@sveltejs/vite-plugin-svelte'
import { defineConfig } from 'vitest/config'

/**
 * This file is the only one that runs in Node and the only variable it needs is the API target.
 * A module scoped declaration keeps it type checked without adding the whole @types/node package.
 */
declare const process: { env: Record<string, string | undefined> }

export default defineConfig({
  plugins: [svelte()],
  server: {
    host: '127.0.0.1',
    port: 5173,
    proxy: {
      '/api': process.env.NECTRIX_API_TARGET ?? 'http://127.0.0.1:8080',
    },
  },
  test: {
    environment: 'jsdom',
    include: ['src/**/*.test.ts'],
  },
})
