// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { invitationTokenFor } from './mailpit';
import { NOTICE_CLOSED, OPERATOR_PASSWORD, signIn as signInAsOperator } from './session';
import { proveIfHeldBack, sendProving } from './step-up';
import { toast } from './toast';

// « Fermer l'entreprise » through the whole stack (the workflow hunt, F24): it existed in the API alone. The owner of a
// company of its own closes it from its profile, typing its name, lands on the page a closed company shows, with the
// day its documents are kept until and « Rouvrir l'entreprise », and takes the closing back, which opens the
// application again. The seed makes the operator Demo's owner, so the company is made here; each run leaves one
// company and one account behind.
const THEIR_PASSWORD = 'a-long-enough-password';

async function signIn(page: Page, email: string, password: string): Promise<void> {
  await page.goto('/login');
  await page.getByTestId('email').fill(email);
  await page.getByTestId('password').fill(password);
  await page.getByTestId('submit').click();
}

test('the owner closes the company from its profile, and reopens it from the page a closed company shows', async ({
  page,
  browser,
  request,
}) => {
  test.setTimeout(180_000);
  const stamp = Date.now();
  const owner = `closing-owner-${stamp}@twes.local`;
  const name = `Fermeture ${stamp}`;

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
  const ownerInvited = await sendProving(
    page,
    OPERATOR_PASSWORD,
    'POST',
    `/api/platform/companies/${companyId}/owners`,
    { email: owner },
  );
  expect(ownerInvited.status).toBe(201);
  const approved = await sendProving(
    page,
    OPERATOR_PASSWORD,
    'POST',
    `/api/platform/companies/${companyId}/approve`,
    {},
  );
  expect(approved.status).toBe(200);

  const theirs = await browser.newContext();
  const theirPage = await theirs.newPage();
  await theirPage.goto('/');
  await theirPage.evaluate((key) => localStorage.setItem(key, 'closed'), NOTICE_CLOSED);
  await theirPage.goto(`/invitations/${await invitationTokenFor(request, owner)}`);
  await theirPage.getByTestId('invitation-name').fill('Closing Owner');
  await theirPage.getByTestId('invitation-password').fill(THEIR_PASSWORD);
  await theirPage.getByTestId('invitation-submit').click();
  await expect(theirPage).toHaveURL(/\/login$/);
  await signIn(theirPage, owner, THEIR_PASSWORD);
  await expect(theirPage).toHaveURL(/\/$/);

  // Closing asks « Définitif à terme » and the company's name typed before its button answers.
  await theirPage.goto('/company/profile');
  await theirPage.getByTestId('company-close').click();
  await expect(theirPage.getByTestId('confirm-kind')).toBeVisible();
  await expect(theirPage.getByTestId('confirm-run')).toBeDisabled();
  await theirPage.getByTestId('confirm-typed').fill(name);
  const closing = theirPage.waitForResponse(
    (response) => response.url().endsWith('/close-down') && response.request().method() === 'POST',
  );
  await theirPage.getByTestId('confirm-run').click();
  expect((await proveIfHeldBack(theirPage, THEIR_PASSWORD, closing)).ok()).toBe(true);

  // The page a closed company shows says it, until when it is kept and until when its owner may take it back.
  await expect(theirPage).toHaveURL(/\/awaiting-approval$/);
  await expect(theirPage.getByTestId('awaiting-closed')).toContainText(name);
  await expect(theirPage.getByTestId('awaiting-closed-kept')).toBeVisible();
  await expect(theirPage.getByTestId('awaiting-reopen-until')).toBeVisible();
  await expect(toast(theirPage)).toContainText(name);

  const reopening = theirPage.waitForResponse(
    (response) => response.url().endsWith('/reopen') && response.request().method() === 'POST',
  );
  await theirPage.getByTestId('awaiting-reopen').click();
  expect((await proveIfHeldBack(theirPage, THEIR_PASSWORD, reopening)).ok()).toBe(true);
  await expect(theirPage).toHaveURL(/\/$/);
  await theirs.close();
});
