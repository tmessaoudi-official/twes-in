// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { invitationTokenFor } from './mailpit';
import { NOTICE_CLOSED, OPERATOR_PASSWORD, signIn as signInAsOperator } from './session';
import { proveIfHeldBack, sendProving } from './step-up';

// A company its unpaid subscription locked (the workflow hunt, R2-2): its owner, shut out of the shell, still reaches
// « Mon compte » outside it, its « Sécurité » alone, and closes the company down from the waiting page, as the API
// allows through the lock; the closed company is then read in the shell under its notice. Each run leaves one company
// and one account behind.
const THEIR_PASSWORD = 'a-long-enough-password';

async function signIn(page: Page, email: string, password: string): Promise<void> {
  await page.goto('/login');
  await page.getByTestId('email').fill(email);
  await page.getByTestId('login-continue').click();
  await page.getByTestId('password').fill(password);
  await page.getByTestId('submit').click();
}

test('the owner of a locked company reaches their account and closes the company from the waiting page', async ({
  page,
  browser,
  request,
}) => {
  test.setTimeout(180_000);
  const stamp = Date.now();
  const owner = `locked-owner-${stamp}@twes.local`;
  const name = `Verrouillée ${stamp}`;

  await signInAsOperator(page);
  const opened = await sendProving(page, OPERATOR_PASSWORD, 'POST', '/api/companies', {
    name,
    countryCode: 'TN',
    currency: 'TND',
    locale: 'fr',
    timezone: 'Africa/Tunis',
  });
  expect(opened.status).toBe(201);
  const companyId = (opened.body as { id: string }).id;
  expect(
    (
      await sendProving(
        page,
        OPERATOR_PASSWORD,
        'POST',
        `/api/platform/companies/${companyId}/owners`,
        { email: owner },
      )
    ).status,
  ).toBe(201);
  expect(
    (
      await sendProving(
        page,
        OPERATOR_PASSWORD,
        'POST',
        `/api/platform/companies/${companyId}/approve`,
        {},
      )
    ).status,
  ).toBe(200);

  const theirs = await browser.newContext();
  const theirPage = await theirs.newPage();
  await theirPage.goto('/');
  await theirPage.evaluate((key) => localStorage.setItem(key, 'closed'), NOTICE_CLOSED);
  await theirPage.goto(`/invitations/${await invitationTokenFor(request, owner)}`);
  await theirPage.getByTestId('invitation-name').fill('Locked Owner');
  await theirPage.getByTestId('invitation-password').fill(THEIR_PASSWORD);
  await theirPage.getByTestId('invitation-submit').click();
  await expect(theirPage).toHaveURL(/\/login$/);
  await signIn(theirPage, owner, THEIR_PASSWORD);
  await expect(theirPage).toHaveURL(/\/$/);

  // Its paid time ended a month ago, no grace, and unpaid means closed.
  const monthAgo = new Date(Date.now() - 30 * 86_400_000).toISOString().slice(0, 10);
  expect(
    (
      await sendProving(
        page,
        OPERATOR_PASSWORD,
        'PUT',
        `/api/platform/companies/${companyId}/subscription`,
        {
          periodCount: 1,
          periodUnit: 'month',
          trialEndsOn: null,
          paidThrough: monthAgo,
          price: '600.000',
          currency: 'TND',
          graceDays: 0,
          unpaidMode: 'locked',
          holdDays: 7,
          version: 0,
        },
      )
    ).status,
  ).toBe(200);
  await theirPage.reload();
  await expect(theirPage).toHaveURL(/\/awaiting-approval/);
  await expect(theirPage.getByTestId('awaiting-locked')).toBeVisible();

  // « Mon compte » opens outside the shell, on « Sécurité » alone, and leads back.
  await theirPage.getByTestId('awaiting-account').click();
  await expect(theirPage).toHaveURL(/\/account$/);
  await expect(theirPage.getByTestId('account-page')).toBeVisible();
  // A lone tab's strip is hidden (styles.scss), so the one panel is read by its name.
  await expect(theirPage.getByRole('tabpanel')).toHaveCount(1);
  await expect(theirPage.getByRole('tabpanel', { name: 'Sécurité' })).toBeVisible();
  await expect(theirPage.locator('app-connected-devices')).toBeVisible();
  await expect(theirPage.locator('app-account-deletion')).toBeVisible();
  await theirPage.screenshot({
    path: test.info().outputPath('locked-account.png'),
    fullPage: true,
  });
  await theirPage.getByTestId('closed-account-back').click();
  await expect(theirPage).toHaveURL(/\/awaiting-approval/);

  // Closing through the lock: « Définitif à terme », the company's name typed, then the proof of who is at the screen.
  await theirPage.getByTestId('awaiting-close').click();
  await expect(theirPage.getByTestId('confirm-run')).toBeDisabled();
  await theirPage.getByTestId('confirm-typed').fill(name);
  const closing = theirPage.waitForResponse(
    (response) => response.url().endsWith('/close-down') && response.request().method() === 'POST',
  );
  await theirPage.getByTestId('confirm-run').click();
  expect((await proveIfHeldBack(theirPage, THEIR_PASSWORD, closing)).ok()).toBe(true);

  // Closed, it is read in the shell under its notice, where its owner may still take the closing back.
  await expect(theirPage).not.toHaveURL(/\/awaiting-approval/);
  await expect(theirPage.getByTestId('closed-notice')).toContainText(name);
  await expect(theirPage.getByTestId('closed-notice-reopen')).toBeVisible();
  await theirs.close();
});
