// SPDX-License-Identifier: AGPL-3.0-or-later
import { defineConfig } from '@playwright/test';

// Runs against the compose stack (web on WEB_PORT, which proxies /api to the API container).
export default defineConfig({
  testDir: './e2e',
  fullyParallel: true,
  forbidOnly: !!process.env['CI'],
  retries: 0,
  reporter: process.env['CI'] ? 'github' : 'list',
  use: {
    baseURL: process.env['BASE_URL'] ?? 'http://127.0.0.1:8090',
    trace: 'retain-on-failure',
  },
  projects: [{ name: 'chromium', use: { browserName: 'chromium' } }],
});
