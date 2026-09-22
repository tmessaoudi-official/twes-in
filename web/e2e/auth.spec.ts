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
  expect(first.csp).toContain(`style-src 'self' 'nonce-${first.header}'`);
  // A stored copy of the document would pair an old nonce with a new header.
  expect(first.cacheControl).toContain('no-store');
});
