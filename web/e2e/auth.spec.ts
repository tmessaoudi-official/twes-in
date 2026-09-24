// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test';
import {
  enterOperatorCode,
  OPERATOR_EMAIL as EMAIL,
  OPERATOR_PASSWORD as PASSWORD,
} from './session';

// The G1a scenario, through the real bundle, nginx, FrankenPHP and PostgreSQL: the seeded operator signs in with
// their password and a code from their authenticator, lands on the hello page with their company, signs out, and is
// back at the login page.

test('an unauthenticated visitor is sent to the login page', async ({ page }) => {
  await page.goto('/');
  await expect(page).toHaveURL(/\/login$/);
  await expect(page.getByTestId('email')).toBeVisible();
});

test('login, hello page, logout', async ({ page }) => {
  test.setTimeout(90_000);
  await page.goto('/login');
  await page.getByTestId('email').fill(EMAIL);
  await page.getByTestId('password').fill(PASSWORD);
  await page.getByTestId('submit').click();
  // An operator must carry a second factor, so the password alone leads to the code step.
  await expect(page).toHaveURL(/\/login$/);
  await enterOperatorCode(page);

  await expect(page).toHaveURL(/\/$/);
  await expect(page.getByTestId('greeting')).toContainText('Bonjour, Operator');
  await expect(page.getByTestId('company-name')).toHaveText('Demo');
  await expect(page.getByTestId('company-line')).toContainText('propriétaire');

  // The session survives a full reload: it is a cookie and a PostgreSQL row, not client state.
  await page.reload();
  await expect(page.getByTestId('greeting')).toContainText('Bonjour, Operator');

  // Signing out lives in the account menu of the shell.
  await page.getByTestId('user-menu').click();
  await page.getByTestId('logout').click();
  await expect(page).toHaveURL(/\/login$/);

  await page.goto('/');
  await expect(page).toHaveURL(/\/login$/);
});

test('a wrong password is refused with a message and no session', async ({ page }) => {
  await page.goto('/login');
  await page.getByTestId('email').fill(EMAIL);
  await page.getByTestId('password').fill('not-the-password');
  await page.getByTestId('submit').click();

  await expect(page.getByTestId('login-error')).toContainText('incorrect');
  await expect(page).toHaveURL(/\/login$/);
  await expect(page.getByTestId('password')).toHaveValue('');
});

test('the responses carry the security headers', async ({ request }) => {
  const response = await request.get('/');
  const csp = response.headers()['content-security-policy'] ?? '';
  expect(csp).toContain("default-src 'self'");
  expect(csp).toContain("frame-ancestors 'none'");
  expect(response.headers()['x-content-type-options']).toBe('nosniff');
});

/**
 * A translation file carried no Cache-Control, so a browser kept the one it had by its own guess and showed a new key
 * raw after a deploy ("inventory.plan.not_saved" on the board, 2026-09-22). It is revalidated on every load now; its
 * ETag keeps that to a 304 when nothing changed.
 */
test('a translation file is revalidated on every load, and the API keeps its own caching', async ({
  request,
}) => {
  const translations = await request.get('/i18n/fr.json');
  expect(translations.ok()).toBe(true);
  expect(translations.headers()['cache-control'] ?? '').toBe('no-cache');

  // The API's own header passes through alone: Symfony's, not a second one added on the way.
  const health = await request.get('/api/health');
  expect(health.headers()['cache-control']).toBe('no-cache, private');
});

/**
 * A file whose name carries its content hash never changes under that name, so a browser may keep it for a year
 * without asking again; without a header each visit revalidated the 3.8 MB icon font and every bundle (PF-09). A
 * file whose name stays the same across releases (a vendored font, the icons of the web manifest) keeps no such
 * promise.
 */
test('a hashed bundle is kept for good, and a file that keeps its name is not', async ({
  request,
}) => {
  const html = await (await request.get('/')).text();
  const hashed = [
    ...html.matchAll(/(?:src|href)="((?:main|styles|chunk)-[A-Za-z0-9_-]{8,9}\.(?:js|css))"/g),
  ].map((match) => match[1]);
  expect(hashed.length, html).toBeGreaterThanOrEqual(2);
  const css = await (await request.get(`/${hashed.find((name) => name.endsWith('.css'))}`)).text();
  const font = /url\("?\.?\/?(media\/material-symbols-outlined-[A-Z0-9]{8}\.woff2)/.exec(css)?.[1];
  expect(font, 'the stylesheet names the hashed icon font').toBeDefined();

  for (const name of [...hashed, font]) {
    const response = await request.get(`/${name}`);
    expect(response.ok(), name).toBe(true);
    expect(response.headers()['cache-control'], name).toBe('public, max-age=31536000, immutable');
  }
  for (const name of ['/fonts/inter/inter-latin-wght-normal.woff2', '/icon-192.png']) {
    const response = await request.get(name);
    expect(response.ok(), name).toBe(true);
    expect(response.headers()['cache-control'] ?? '', name).not.toContain('immutable');
  }
});

test('every page load carries a fresh CSP nonce, shared by the header and the document', async ({
  request,
}) => {
  const load = async () => {
    const response = await request.get('/members');
    const csp = response.headers()['content-security-policy'] ?? '';
    return {
      csp,
      header: /'nonce-([A-Za-z0-9+/=_-]{16,})'/.exec(csp)?.[1],
      document: /ngCspNonce="([^"]+)"/i.exec(await response.text())?.[1],
      cacheControl: response.headers()['cache-control'] ?? '',
    };
  };

  const first = await load();
  const second = await load();

  expect(first.header, first.csp).toBeDefined();
  expect(first.document).toBe(first.header);
  expect(second.header).not.toBe(first.header);
  expect(first.csp).not.toContain('unsafe-inline');
  expect(first.csp).toContain(`script-src 'self' 'nonce-${first.header}'`);
  // WebAssembly may compile (the camera's decoder); JavaScript's eval stays refused.
  expect(first.csp).toContain("'wasm-unsafe-eval'");
  expect(first.csp).not.toContain("'unsafe-eval'");
  expect(first.csp).toContain(`style-src 'self' 'nonce-${first.header}'`);
  // A stored copy of the document would pair an old nonce with a new header.
  expect(first.cacheControl).toContain('no-store');
});
