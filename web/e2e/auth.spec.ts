// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test';

// The G1a scenario, through the real bundle, nginx, FrankenPHP and PostgreSQL: the seeded operator signs in,
// lands on the hello page with their company, signs out, and is back at the login page. The credentials are
// the ones `make seed` and the CI e2e job pass to `app:seed`.
const EMAIL = process.env['E2E_EMAIL'] ?? 'operator@twes.local';
const PASSWORD = process.env['E2E_PASSWORD'] ?? 'twes-operator-dev';

test('an unauthenticated visitor is sent to the login page', async ({ page }) => {
  await page.goto('/');
  await expect(page).toHaveURL(/\/login$/);
  await expect(page.getByTestId('email')).toBeVisible();
});

test('login, hello page, logout', async ({ page }) => {
  await page.goto('/login');
  await page.getByTestId('email').fill(EMAIL);
  await page.getByTestId('password').fill(PASSWORD);
  await page.getByTestId('submit').click();

  await expect(page).toHaveURL(/\/$/);
  await expect(page.getByTestId('greeting')).toContainText('Bonjour, Operator');
  await expect(page.getByTestId('company-name')).toHaveText('Demo');
  await expect(page.getByTestId('company-line')).toContainText('propriétaire');

  // The session survives a full reload: it is a cookie and a PostgreSQL row, not client state.
  await page.reload();
  await expect(page.getByTestId('greeting')).toContainText('Bonjour, Operator');

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
