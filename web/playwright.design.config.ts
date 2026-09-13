// SPDX-License-Identifier: AGPL-3.0-or-later
import { defineConfig } from '@playwright/test';

// The G2b design checkpoint: screenshots of the fixture screens at desktop and phone width in both schemes,
// against `ng serve` (a development build, the only one with /design) with every /api call answered by fixtures,
// so no stack is needed. Not part of CI. Its axe and overflow checks are soft, so every screen is still captured;
// read the report for them. Run: npx playwright test -c playwright.design.config.ts
export default defineConfig({
  testDir: './e2e-design',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: 'list',
  // `ng serve` compiles each lazy route on its first request; under load that alone took over 5 s (2026-09-13).
  expect: { timeout: 20_000 },
  use: {
    baseURL: process.env['BASE_URL'] ?? 'http://127.0.0.1:4200',
    browserName: 'chromium',
    // Native date inputs format in the browser's locale: show them as a French user in Tunis sees them.
    locale: 'fr-FR',
    timezoneId: 'Africa/Tunis',
    channel: process.env['PLAYWRIGHT_CHANNEL'] || undefined,
  },
  webServer: {
    command: 'npx ng serve --host 127.0.0.1 --port 4200',
    url: 'http://127.0.0.1:4200',
    reuseExistingServer: true,
    timeout: 300_000,
  },
});
