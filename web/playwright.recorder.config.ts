// SPDX-License-Identifier: AGPL-3.0-or-later
import { defineConfig } from '@playwright/test';

// The guide videos: each guide played on the running app and filmed, with its narration drawn in the page as
// captions, into var/claude/videos. Not part of CI: the tours are proven by `e2e/tours.spec.ts`, and a recording is
// made for a release, alone on the machine (`make videos`, docs/START.md § 12). A recording opens its own context with
// `recordVideo`, so no `use.video` here: the default context would leave a second, useless video.
export default defineConfig({
  testDir: './e2e-recorder',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: 'list',
  timeout: 300_000,
  expect: { timeout: 15_000 },
  use: {
    baseURL: process.env['BASE_URL'] ?? 'http://127.0.0.1:8090',
    locale: 'fr-FR',
    timezoneId: 'Africa/Tunis',
  },
  projects: [
    {
      name: 'record',
      testMatch: ['**/*.test.ts', '**/*.rec.ts'],
      testIgnore: ['**/check-*.rec.ts'],
      use: { browserName: 'chromium', channel: process.env['PLAYWRIGHT_CHANNEL'] || undefined },
    },
    {
      // Each video is opened again in the browser and looked at, chapter by chapter, once it is written.
      name: 'check',
      testMatch: ['**/check-*.rec.ts'],
      dependencies: ['record'],
      use: { browserName: 'chromium', channel: process.env['PLAYWRIGHT_CHANNEL'] || undefined },
    },
  ],
});
