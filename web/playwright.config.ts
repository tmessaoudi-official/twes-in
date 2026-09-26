// SPDX-License-Identifier: AGPL-3.0-or-later
import { defineConfig } from '@playwright/test';

const baseURL = process.env['BASE_URL'] ?? 'http://127.0.0.1:8090';

// A scenario is not a first visit: the cookie notice (row 149) starts closed, on the address the suite uses and on
// localhost, which passkeys.spec.ts talks to. A context a scenario opens itself starts empty; `signIn` closes it there,
// and legal.spec.ts opens a truly fresh one to test the notice.
const noticeClosed = {
  cookies: [],
  origins: [baseURL, baseURL.replace('127.0.0.1', 'localhost')].map((origin) => ({
    origin: new URL(origin).origin,
    localStorage: [{ name: 'twes.cookie-notice', value: 'closed' }],
  })),
};

// Runs against the compose stack (web on WEB_PORT, which proxies /api to the API container).
export default defineConfig({
  testDir: './e2e',
  // One database behind the whole stack, and these scenarios change shared rows (a company, a membership).
  // Running files in parallel would let one test's setup change what another asserts, so the suite is serial.
  fullyParallel: false,
  workers: 1,
  forbidOnly: !!process.env['CI'],
  retries: 0,
  reporter: process.env['CI'] ? 'github' : 'list',
  use: {
    baseURL,
    trace: 'retain-on-failure',
    // The scheme defaults to Automatique, which follows the device: every run starts from a light device, and a
    // scenario about the dark one says so with emulateMedia.
    colorScheme: 'light',
  },
  // CI downloads Playwright's own Chromium. A machine that cannot reach Playwright's browser CDN runs the same
  // engine through an installed Chrome instead: PLAYWRIGHT_CHANNEL=chrome npx playwright test.
  // The setup project signs the operator in once, with a code, before any scenario runs (e2e/session.ts says why).
  projects: [
    {
      name: 'setup',
      testMatch: /\.setup\.ts$/,
      use: { browserName: 'chromium', channel: process.env['PLAYWRIGHT_CHANNEL'] || undefined },
    },
    {
      name: 'chromium',
      dependencies: ['setup'],
      use: {
        browserName: 'chromium',
        channel: process.env['PLAYWRIGHT_CHANNEL'] || undefined,
        storageState: noticeClosed,
      },
    },
  ],
});
