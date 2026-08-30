// SPDX-License-Identifier: AGPL-3.0-or-later

import { defineConfig } from '@playwright/test'

export default defineConfig({
  testDir: './e2e',
  // Real clipboard: Chromium needs explicit permissions for reliable copy/cut/paste in tests.
  use: { baseURL: 'http://127.0.0.1:5174', permissions: ['clipboard-read', 'clipboard-write'] },
  webServer: [
    // Il database e2e viene ricreato a ogni avvio: le esecuzioni restano indipendenti fra loro.
    { command: 'rm -f /tmp/chaorganix.e2e.sqlite && CHAORGANIX_DB_PATH=/tmp/chaorganix.e2e.sqlite php -S 127.0.0.1:8081 -t api/public api/public/index.php', url: 'http://127.0.0.1:8081/api/health', reuseExistingServer: false },
    { command: 'CHAORGANIX_API_TARGET=http://127.0.0.1:8081 npm run dev -- --host 127.0.0.1 --port 5174', url: 'http://127.0.0.1:5174', reuseExistingServer: false },
  ],
})
