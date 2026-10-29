// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Locator, type Page, test } from '@playwright/test';
import { invitationTokenFor } from './mailpit';
import { OPERATOR_PASSWORD, signIn as signInAsOperator } from './session';
import { proveIfHeldBack, sendProving } from './step-up';
import { axeAtRest } from './axe';

// C3 through the whole stack (docs/SPEC.md § 7, 2026-09-15): an operator finds an account on /platform, ends its
// sessions, deactivates it and reactivates it, while that account's own browser is signed in beside it. Each run
// leaves one account behind, a member of Demo, because accounts are never deleted.
const THEIR_PASSWORD = 'a-long-enough-password';

async function signIn(page: Page, email: string, password: string): Promise<void> {
  await page.goto('/login');
  await page.getByTestId('email').fill(email);
  await page.getByTestId('login-continue').click();
  await page.getByTestId('password').fill(password);
  await page.getByTestId('submit').click();
  await expect(page).toHaveURL(/\/$/);
}

/** Takes an entry of a row's « ⋮ », whose ids carry the account's id rather than its address, and confirms it. */
async function fromRowMenu(page: Page, line: Locator, action: string): Promise<void> {
  await line.locator('[data-testid^="row-more-"]').click();
  await page.locator(`[data-testid^="row-menu-${action}-"]`).click();
  await expect(page.getByTestId('confirm-message')).toBeVisible();
  await page.getByTestId('confirm-run').click();
}

async function expectAccessible(page: Page, screen: string): Promise<void> {
  const results = await (
    await axeAtRest(page)
  )
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
  await signInAsOperator(page);
  // Adding a member and every platform write wait for the operator's recent proof (docs/SPEC.md § 7), given only
  // when asked: every attempt counts against the operator's five in five minutes.
  const companyId = await page.evaluate(
    async () =>
      ((await (await fetch('/api/auth/me')).json()) as { company: { id: string } }).company.id,
  );
  const members = `/api/companies/${companyId}/members`;
  const invited = await sendProving(page, OPERATOR_PASSWORD, 'POST', members, {
    email: managed,
    role: 'member',
  });
  expect(invited.status).toBe(201);
  const theirs = await browser.newContext();
  const theirPage = await theirs.newPage();
  await theirPage.goto(`/invitations/${await invitationTokenFor(request, managed)}`);
  await theirPage.getByTestId('invitation-name').fill('Managed Person');
  await theirPage.getByTestId('invitation-password').fill(THEIR_PASSWORD);
  await theirPage.getByTestId('invitation-submit').click();
  await expect(theirPage).toHaveURL(/\/login$/);
  await signIn(theirPage, managed, THEIR_PASSWORD);

  // The operator finds it.
  await page.goto(`/platform?tab=accounts&q=${encodeURIComponent(managed)}`);
  const line = page.getByTestId(`account-${managed}`);
  await expect(line).toContainText('Actif');
  await expectAccessible(page, 'platform accounts');
  await page.screenshot({ path: test.info().outputPath('platform-accounts.png'), fullPage: true });

  // Ending the sessions sends their open browser back to the login page on its next request.
  const ended = page.waitForResponse(
    (response) =>
      response.url().endsWith('/end-sessions') && response.request().method() === 'POST',
  );
  await fromRowMenu(page, line, 'end-sessions');
  // Only once the API has ended them: a reload racing the click can still carry the old session.
  expect((await proveIfHeldBack(page, OPERATOR_PASSWORD, ended)).ok()).toBe(true);
  await theirPage.reload();
  await expect(theirPage).toHaveURL(/\/login/);
  await signIn(theirPage, managed, THEIR_PASSWORD);

  // Deactivating signs them out again, and they cannot sign back in.
  const deactivated = page.waitForResponse(
    (response) => response.url().endsWith('/deactivate') && response.request().method() === 'POST',
  );
  await fromRowMenu(page, line, 'deactivate');
  await proveIfHeldBack(page, OPERATOR_PASSWORD, deactivated);
  await expect(line).toContainText('Désactivé');
  await page.screenshot({
    path: test.info().outputPath('platform-account-deactivated.png'),
    fullPage: true,
  });
  await theirPage.reload();
  await expect(theirPage).toHaveURL(/\/login/);
  await theirPage.getByTestId('email').fill(managed);
  await theirPage.getByTestId('login-continue').click();
  await theirPage.getByTestId('password').fill(THEIR_PASSWORD);
  await theirPage.getByTestId('submit').click();
  await expect(theirPage.getByTestId('login-error')).toBeVisible();
  await expect(theirPage).toHaveURL(/\/login/);

  // Reactivated, the same password works again.
  const reactivated = page.waitForResponse(
    (response) => response.url().endsWith('/reactivate') && response.request().method() === 'POST',
  );
  await line.locator('[data-testid^="row-action-reactivate-"]').click();
  await proveIfHeldBack(page, OPERATOR_PASSWORD, reactivated);
  await expect(line).toContainText('Actif');
  await signIn(theirPage, managed, THEIR_PASSWORD);
  await theirs.close();
});
