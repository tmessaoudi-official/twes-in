// SPDX-License-Identifier: AGPL-3.0-or-later
import AxeBuilder from '@axe-core/playwright';
import { expect, type Page, test } from '@playwright/test';
import { invitationTokenFor } from './mailpit';

// C3 through the whole stack (docs/SPEC.md § 7, 2026-09-15): an operator finds an account on /platform, ends its
// sessions, deactivates it and reactivates it, while that account's own browser is signed in beside it. Each run
// leaves one account behind, a member of Demo, because accounts are never deleted.
const EMAIL = process.env['E2E_EMAIL'] ?? 'operator@twes.local';
const PASSWORD = process.env['E2E_PASSWORD'] ?? 'twes-operator-dev';
const THEIR_PASSWORD = 'a-long-enough-password';
const CSRF = '0123456789abcdef0123456789abcdef';

async function signIn(page: Page, email: string, password: string): Promise<void> {
  await page.goto('/login');
  await page.getByTestId('email').fill(email);
  await page.getByTestId('password').fill(password);
  await page.getByTestId('submit').click();
  await expect(page).toHaveURL(/\/$/);
}

async function expectAccessible(page: Page, screen: string): Promise<void> {
  const results = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
    .analyze();
  const violations = results.violations.map(
    (violation) =>
      `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`,
  );
  expect(violations, screen).toEqual([]);
}

test('an operator ends the sessions of an account, deactivates it and reactivates it', async ({
  page,
  browser,
  request,
}) => {
  test.setTimeout(120_000);
  const managed = `managed-${Date.now()}@twes.local`;

  // The account: somebody invited into Demo who made their account from the link, in a browser of their own.
  await signIn(page, EMAIL, PASSWORD);
  const invited = await page.evaluate(
    async ([csrf, email]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const response = await fetch(`/api/companies/${me.company.id}/members`, {
        method: 'POST',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: JSON.stringify({ email, role: 'member' }),
      });
      return response.status;
    },
    [CSRF, managed] as const,
  );
  expect(invited).toBe(201);
  const theirs = await browser.newContext();
  const theirPage = await theirs.newPage();
  await theirPage.goto(`/invitations/${await invitationTokenFor(request, managed)}`);
  await theirPage.getByTestId('invitation-name').fill('Managed Person');
  await theirPage.getByTestId('invitation-password').fill(THEIR_PASSWORD);
  await theirPage.getByTestId('invitation-submit').click();
  await expect(theirPage).toHaveURL(/\/login$/);
  await signIn(theirPage, managed, THEIR_PASSWORD);

  // The operator finds it.
  await page.goto('/platform');
  await page.getByTestId('platform-account-search').fill(managed);
  await page.getByTestId('platform-account-find').click();
  const line = page.getByTestId(`account-${managed}`);
  await expect(line).toContainText('Actif');
  await expectAccessible(page, 'platform accounts');
  await page.screenshot({ path: test.info().outputPath('platform-accounts.png'), fullPage: true });

  // Ending the sessions sends their open browser back to the login page on its next request.
  await page.getByTestId(`end-sessions-${managed}`).click();
  await theirPage.reload();
  await expect(theirPage).toHaveURL(/\/login/);
  await signIn(theirPage, managed, THEIR_PASSWORD);

  // Deactivating signs them out again, and they cannot sign back in.
  await page.getByTestId(`deactivate-${managed}`).click();
  await expect(line).toContainText('Désactivé');
  await page.screenshot({
    path: test.info().outputPath('platform-account-deactivated.png'),
    fullPage: true,
  });
  await theirPage.reload();
  await expect(theirPage).toHaveURL(/\/login/);
  await theirPage.getByTestId('email').fill(managed);
  await theirPage.getByTestId('password').fill(THEIR_PASSWORD);
  await theirPage.getByTestId('submit').click();
  await expect(theirPage.getByTestId('login-error')).toBeVisible();
  await expect(theirPage).toHaveURL(/\/login/);

  // Reactivated, the same password works again.
  await page.getByTestId(`reactivate-${managed}`).click();
  await expect(line).toContainText('Actif');
  await signIn(theirPage, managed, THEIR_PASSWORD);
  await theirs.close();
});
