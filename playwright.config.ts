// SPDX-License-Identifier: AGPL-3.0-or-later

import { defineConfig } from '@playwright/test'

export default defineConfig({
  testDir: './e2e',
  use: { baseURL: 'http://127.0.0.1:5174' },
  webServer: [
    { command: 'NECTRIX_DB_PATH=/tmp/nectrix.e2e.sqlite php -S 127.0.0.1:8081 -t api/public api/public/index.php', url: 'http://127.0.0.1:8081/api/health', reuseExistingServer: false },
    { command: 'NECTRIX_API_TARGET=http://127.0.0.1:8081 npm run dev -- --host 127.0.0.1 --port 5174', url: 'http://127.0.0.1:5174', reuseExistingServer: false },
  ],
})
