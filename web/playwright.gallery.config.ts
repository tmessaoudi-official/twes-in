// SPDX-License-Identifier: AGPL-3.0-or-later
import { defineConfig } from '@playwright/test';

// The screen gallery (docs/SPEC.md § 7, 2026-09-19): every route of the running stack, desktop and phone, light and
// dark, captured for a design review. Not part of CI: it proves nothing, it shows. The setup project signs the
// operator in once, as the e2e suite does. Run against `make up`: npx playwright test -c playwright.gallery.config.ts
export default defineConfig({
  testDir: './e2e-gallery',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: 'list',
  timeout: 600_000,
  expect: { timeout: 15_000 },
  use: {
    baseURL: process.env['BASE_URL'] ?? 'http://127.0.0.1:8090',
    locale: 'fr-FR',
    timezoneId: 'Africa/Tunis',
  },
  projects: [
    {
      name: 'setup',
      testDir: './e2e',
      testMatch: /operator\.setup\.ts$/,
      use: { browserName: 'chromium', channel: process.env['PLAYWRIGHT_CHANNEL'] || undefined },
    },
    {
      name: 'gallery',
      dependencies: ['setup'],
      use: { browserName: 'chromium', channel: process.env['PLAYWRIGHT_CHANNEL'] || undefined },
    },
  ],
});
