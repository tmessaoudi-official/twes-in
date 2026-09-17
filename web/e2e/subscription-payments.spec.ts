// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { invitationTokenFor } from './mailpit';
import { signIn as signInAsOperator } from './session';

// Slice 2 through the whole stack (docs/SPEC.md § 7, 2026-09-17): an operator locks a company for not paying, its
// owner is shut out of the shell and follows the one way back, declares what they paid, the operator sees it waiting
// and confirms it, and the owner is in again. Each run leaves one company and one account behind: neither is deleted.
const THEIR_PASSWORD = 'a-long-enough-password';
const CSRF = '0123456789abcdef0123456789abcdef';

async function signIn(page: Page, email: string, password: string): Promise<void> {
  await page.goto('/login');
  await page.getByTestId('email').fill(email);
  await page.getByTestId('password').fill(password);
  await page.getByTestId('submit').click();
}

/** Calls the API from inside the page, which is the only way the session cookie travels (SameSite=Strict). */
async function api(
  page: Page,
  method: string,
  path: string,
  body: unknown,
): Promise<{ status: number; body: unknown }> {
  return page.evaluate(
    async ([csrf, verb, url, payload]) => {
      const response = await fetch(url as string, {
        method: verb as string,
        headers: { 'content-type': 'application/json', 'csrf-token': csrf as string },
        body: payload === null ? null : JSON.stringify(payload),
      });
      return { status: response.status, body: await response.json().catch(() => null) };
    },
    [CSRF, method, path, body] as const,
  );
}

test('a locked company declares what it paid and the operator confirms it back open', async ({
  page,
  browser,
  request,
}) => {
  test.setTimeout(180_000);
  const stamp = Date.now();
  const owner = `payer-${stamp}@twes.local`;

  // A company of its own, with an owner who made their account from the invitation.
  await signInAsOperator(page);
  const opened = await api(page, 'POST', '/api/companies', {
    name: `Payante ${stamp}`,
    countryCode: 'TN',
    currency: 'TND',
    locale: 'fr',
    timezone: 'Africa/Tunis',
  });
  expect(opened.status).toBe(201);
  const companyId = (opened.body as { id: string }).id;
  expect(
    (await api(page, 'POST', `/api/platform/companies/${companyId}/owners`, { email: owner }))
      .status,
  ).toBe(201);
  expect((await api(page, 'POST', `/api/platform/companies/${companyId}/approve`, {})).status).toBe(
    200,
  );

  const theirs = await browser.newContext();
  const theirPage = await theirs.newPage();
  await theirPage.goto(`/invitations/${await invitationTokenFor(request, owner)}`);
  await theirPage.getByTestId('invitation-name').fill('Payante Owner');
  await theirPage.getByTestId('invitation-password').fill(THEIR_PASSWORD);
  await theirPage.getByTestId('invitation-submit').click();
  await expect(theirPage).toHaveURL(/\/login$/);
  await signIn(theirPage, owner, THEIR_PASSWORD);
  await expect(theirPage).toHaveURL(/\/$/);

  // The operator locks it: its paid time ended a month ago, no grace, and unpaid means closed.
  const yesterday = new Date(Date.now() - 30 * 86_400_000).toISOString().slice(0, 10);
  expect(
    (
      await api(page, 'PUT', `/api/platform/companies/${companyId}/subscription`, {
        periodCount: 1,
        periodUnit: 'month',
        trialEndsOn: null,
        paidThrough: yesterday,
        price: '600.000',
        currency: 'TND',
        graceDays: 0,
        unpaidMode: 'locked',
        holdDays: 7,
      })
    ).status,
  ).toBe(200);

  // The owner is shut out of the shell, and the page that says so offers the one way back.
  await theirPage.reload();
  await expect(theirPage).toHaveURL(/\/awaiting-approval/);
  await expect(theirPage.getByTestId('awaiting-locked')).toBeVisible();
  await theirPage.screenshot({
    path: test.info().outputPath('subscription-locked.png'),
    fullPage: true,
  });
  await theirPage.getByTestId('awaiting-declare').click();
  await expect(theirPage).toHaveURL(/\/subscription/);
  await expect(theirPage.getByTestId('subscription-stage')).toContainText('Impayé');

  // They say what they paid.
  await theirPage.getByTestId('subscription-declare').click();
  await theirPage.getByTestId('field-amount').fill('600.000');
  await theirPage.getByTestId('field-paidOn').fill(new Date().toISOString().slice(0, 10));
  await theirPage.getByTestId('field-reference').fill('REC-12');
  await theirPage.getByTestId('payment-submit').click();
  await expect(theirPage.getByTestId('subscription-waiting')).toBeVisible();
  // The form closes once the declaration is in: what it said is now the waiting line above it.
  await expect(theirPage.getByTestId('subscription-form-card')).toBeHidden();
  // Held: the declaration holds the lock off, so the company works again while it waits.
  await expect(theirPage.getByTestId('subscription-stage')).toContainText('Règlement en attente');
  await theirPage.screenshot({
    path: test.info().outputPath('subscription-declared.png'),
    fullPage: true,
  });

  // The operator sees it in their queue, with the company it came from, and the list says the same.
  await page.goto('/platform');
  // The card, not one of the controls inside it: those carry data-testids beginning with "payment-" too.
  const waiting = page
    .locator('mat-card[data-testid^="payment-"]')
    .filter({ hasText: `Payante ${stamp}` });
  await expect(waiting.first()).toContainText('600.000');
  await page.screenshot({ path: test.info().outputPath('platform-payments.png'), fullPage: true });
  await expect(page.getByTestId(`company-Payante ${stamp}`)).toContainText('Règlement en attente');

  // Confirming carries the covered time forward, and the owner is back in the shell.
  const declarationId = await waiting
    .first()
    .getAttribute('data-testid')
    .then((value) => (value ?? '').replace('payment-', ''));
  await page.getByTestId(`payment-confirm-${declarationId}`).click();
  await expect(page.getByTestId(`payment-${declarationId}`)).toHaveCount(0);

  await theirPage.goto('/');
  await expect(theirPage).toHaveURL(/\/$/);
  await theirPage.goto('/company/subscription');
  await expect(theirPage.getByTestId('subscription-stage')).toContainText('À jour');
  await expect(theirPage.getByTestId('subscription-payments')).toContainText('Confirmé');
  await theirPage.screenshot({
    path: test.info().outputPath('subscription-paid.png'),
    fullPage: true,
  });
  await theirs.close();
});
