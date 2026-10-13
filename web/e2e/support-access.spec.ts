// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { invitationTokenFor } from './mailpit';
import { OPERATOR_PASSWORD, signIn as signInAsOperator, signInWithCode } from './session';
import { proveByApi } from './step-up';

// « Accès du support » through the whole stack (docs/SPEC.md § 8 row 67): the owner of a company the operator is NOT a
// member of opens it to support for a day; the operator finds it in their switcher, reads it under a banner and is
// refused a write; the owner sees the visit in the activity log and closes the access, which takes the company out of
// the operator's switcher again. The seed makes the operator Demo's owner, so the company is made here. Each run
// leaves one company and one account behind: neither is deleted.
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

async function companies(page: Page): Promise<{ name: string; role: string }[]> {
  const answered = await api(page, 'GET', '/api/me/companies', null);
  const body = answered.body as
    { member?: { name: string; role: string }[] } | { name: string; role: string }[];
  return Array.isArray(body) ? body : (body.member ?? []);
}

test('an owner opens the company to support for a day, support reads it read-only, and the owner closes it', async ({
  page,
  browser,
  request,
}) => {
  test.setTimeout(240_000);
  const stamp = Date.now();
  const owner = `support-owner-${stamp}@twes.local`;
  const name = `Support ${stamp}`;

  // A company of its own, which the operator opens for its owner without becoming a member of it.
  await signInAsOperator(page);
  // Every platform write, opening a company first, waits for the operator's recent proof (docs/SPEC.md § 7).
  await proveByApi(page, OPERATOR_PASSWORD);
  const opened = await api(page, 'POST', '/api/companies', {
    name,
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
  expect((await companies(page)).map((row) => row.name)).not.toContain(name);

  const theirs = await browser.newContext();
  const theirPage = await theirs.newPage();
  await theirPage.goto(`/invitations/${await invitationTokenFor(request, owner)}`);
  await theirPage.getByTestId('invitation-name').fill('Support Owner');
  await theirPage.getByTestId('invitation-password').fill(THEIR_PASSWORD);
  await theirPage.getByTestId('invitation-submit').click();
  await expect(theirPage).toHaveURL(/\/login$/);
  await signIn(theirPage, owner, THEIR_PASSWORD);
  await expect(theirPage).toHaveURL(/\/$/);

  // The owner opens it for the day offered first, once they have proved who they are again: a sign-in is no proof.
  await theirPage.goto('/company/support-access');
  await expect(theirPage.getByTestId('support-access-closed')).toBeVisible();
  await expect(theirPage.getByTestId('support-access-hours-24').locator('input')).toBeChecked();
  await theirPage.getByTestId('support-access-grant').click();
  await theirPage.getByTestId('step-up-password').fill(THEIR_PASSWORD);
  await theirPage.getByTestId('step-up-confirm').click();
  await expect(theirPage.getByTestId('support-access-open')).toBeVisible();
  await expect(theirPage.getByTestId('support-banner')).toBeVisible();

  // The operator, on a session of their own, since switching moves a session to another company.
  const operators = await browser.newContext();
  const operatorPage = await operators.newPage();
  await signInWithCode(operatorPage);
  await operatorPage.evaluate((key) => localStorage.setItem(key, 'closed'), 'twes.cookie-notice');
  expect(await companies(operatorPage)).toContainEqual(
    expect.objectContaining({ name, role: 'support' }),
  );
  expect((await api(operatorPage, 'POST', '/api/me/company', { companyId })).status).toBe(201);
  await operatorPage.goto('/customers');
  await expect(operatorPage.getByTestId('support-banner')).toContainText(name);

  // Read-only, whatever the screen offers.
  const write = await api(operatorPage, 'POST', `/api/companies/${companyId}/customers`, {});
  expect(write.status).toBe(403);
  expect(write.body).toEqual({ error: 'support_read_only' });

  // The visit is in the owner's activity log.
  await theirPage.goto('/company/activity');
  await expect(theirPage.getByText('est entré pour le support').first()).toBeVisible();

  // Closed again: the company leaves the operator's switcher, and its pages answer as a stranger's.
  await theirPage.goto('/company/support-access');
  await theirPage.getByTestId('support-access-end').click();
  await expect(theirPage.getByTestId('support-access-closed')).toBeVisible();
  expect((await companies(operatorPage)).map((row) => row.name)).not.toContain(name);
  expect(
    (await api(operatorPage, 'GET', `/api/companies/${companyId}/customers`, null)).status,
  ).toBe(404);

  await operators.close();
  await theirs.close();
});
