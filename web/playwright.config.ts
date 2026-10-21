// SPDX-License-Identifier: AGPL-3.0-or-later
import { defineConfig } from '@playwright/test';

const baseURL = process.env['BASE_URL'] ?? 'http://127.0.0.1:8090';
// The stack's HTTPS door (the `lan` service), the address a phone in a shop reaches the app at. WebKit sends no
// `Secure` cookie over plain http, not even to 127.0.0.1, so Safari's engine is driven through it or not at all.
const lanOrigin = process.env['LAN_ORIGIN'] || undefined;
if (lanOrigin === undefined) {
  const why =
    'LAN_ORIGIN is not set, so the @phone scenarios cannot run on WebKit (make up starts the HTTPS door).';
  if (process.env['CI']) throw new Error(why);
  console.warn(why);
}

// A scenario is not a first visit: the cookie notice (row 149) starts closed, on the address the suite uses and on
// localhost, which passkeys.spec.ts talks to. A context a scenario opens itself starts empty; `signIn` closes it there,
// and legal.spec.ts opens a truly fresh one to test the notice.
const noticeClosed = {
  cookies: [],
  origins: [
    baseURL,
    baseURL.replace('127.0.0.1', 'localhost'),
    ...(lanOrigin ? [lanOrigin] : []),
  ].map((origin) => ({
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
    // Safari's engine, which every iPhone runs whatever its browser: the scenarios tagged @phone, the pages a shop's
    // staff open on a phone (the scanner lent to a computer, the bottom bar, the customer screen), run on it too,
    // through the HTTPS door, whose certificate comes from the stack's own authority.
    ...(lanOrigin === undefined
      ? []
      : [
          {
            name: 'webkit',
            dependencies: ['setup'],
            grep: /@phone/,
            use: {
              browserName: 'webkit' as const,
              baseURL: lanOrigin,
              ignoreHTTPSErrors: true,
              storageState: noticeClosed,
            },
          },
        ]),
  ],
});
