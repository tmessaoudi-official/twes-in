// SPDX-License-Identifier: AGPL-3.0-or-later
import { defineConfig } from '@playwright/test';

// The screen gallery (docs/SPEC.md § 7, 2026-09-19): every route of the running stack, desktop and phone, light and
// dark, captured for a design review. Not part of CI: it proves nothing, it shows. It signs the operator in on a
// session of its own and works in a `make fixtures` company (GALLERY_COMPANY, Carthage Conseil by default), so the
// e2e suite's saved session is never moved. Run against `make up` then `make fixtures`: `make gallery`.
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
      name: 'gallery',
      use: { browserName: 'chromium', channel: process.env['PLAYWRIGHT_CHANNEL'] || undefined },
    },
  ],
});
