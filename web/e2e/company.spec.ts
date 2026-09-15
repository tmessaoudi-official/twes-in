// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, Page, test } from '@playwright/test';
import { invitationTokenFor } from './mailpit';

// The G1b switcher, through the real bundle, nginx, FrankenPHP and PostgreSQL. The second company is opened
// through the API because opening one is a platform-operator action with no page of its own yet; everything
// that is asserted happens in the browser.
//
// The scenario puts the operator in a second company and has that company's owner take them out again at the
// end. It has to: the seeded operator belongs to exactly one company, which is what the G1a scenario asserts,
// and one database is shared by the whole suite.
const EMAIL = process.env['E2E_EMAIL'] ?? 'operator@twes.local';
const PASSWORD = process.env['E2E_PASSWORD'] ?? 'twes-operator-dev';

// The SPA sends one random token per page load as a header; any value of the right shape is accepted, and
// the origin proof comes from the request being made by the page itself.
const CSRF = '0123456789abcdef0123456789abcdef';
const OWNER_PASSWORD = 'a-long-enough-password';

async function signIn(page: Page, email = EMAIL, password = PASSWORD): Promise<void> {
  await page.goto('/login');
  await page.getByTestId('email').fill(email);
  await page.getByTestId('password').fill(password);
  await page.getByTestId('submit').click();
  await expect(page).toHaveURL(/\/$/);
}

test('the switcher moves the session to another company, and it survives a reload', async ({
  page,
  browser,
  request,
}) => {
  test.setTimeout(90_000);
  await signIn(page);
  const name = `Globex ${Date.now()}`;
  const owner = `globex-owner-${Date.now()}@twes.local`;

  // The operator is invited as a plain member, and an owner of the company's own takes them out again at the
  // end: a member removes nobody, not even themselves, and the last owner of a company is never removed.
  const setup = await page.evaluate(
    async ([companyName, csrf, ownerEmail, email]) => {
      const headers = { 'content-type': 'application/json', 'csrf-token': csrf };
      const created = await fetch('/api/companies', {
        method: 'POST',
        headers,
        body: JSON.stringify({
          name: companyName,
          countryCode: 'TN',
          currency: 'TND',
          locale: 'fr',
          timezone: 'Africa/Tunis',
        }),
      });
      const company = (await created.json()) as { id: string; status: string };
      const invite = async (address: string, role: string): Promise<number> =>
        (
          await fetch(`/api/companies/${company.id}/members`, {
            method: 'POST',
            headers,
            body: JSON.stringify({ email: address, role }),
          })
        ).status;
      return {
        created: created.status,
        invited: [await invite(ownerEmail, 'owner'), await invite(email, 'member')],
        status: company.status,
        id: company.id,
      };
    },
    [name, CSRF, owner, EMAIL] as const,
  );

  expect(setup.created).toBe(201);
  expect(setup.invited).toEqual([201, 201]);
  // A company an operator opens waits for its first owner.
  expect(setup.status).toBe('pending');

  // The owner is somebody else, in a browser of their own; accepting makes their account.
  const theirs = await browser.newContext();
  const ownerPage = await theirs.newPage();
  await ownerPage.goto(`/invitations/${await invitationTokenFor(request, owner)}`);
  await ownerPage.getByTestId('invitation-name').fill('Globex Owner');
  await ownerPage.getByTestId('invitation-password').fill(OWNER_PASSWORD);
  await ownerPage.getByTestId('invitation-submit').click();
  await expect(ownerPage).toHaveURL(/\/login$/);

  try {
    // The operator already has an account, so accepting asks for nothing (docs/SPEC.md § 7, 2026-09-15).
    const token = await invitationTokenFor(request, EMAIL);
    const accepted = await page.evaluate(
      async ([link, csrf]) =>
        (
          await fetch(`/api/invitations/${link}/accept`, {
            method: 'POST',
            headers: { 'content-type': 'application/json', 'csrf-token': csrf },
            body: '{}',
          })
        ).status,
      [token, CSRF] as const,
    );
    expect(accepted).toBe(201);

    await page.reload();
    await expect(page.getByTestId('company-name')).toHaveText('Demo');

    await page.getByTestId('company-switcher').click();
    await page.getByTestId(`company-option-${name}`).click();
    await expect(page.getByTestId('company-name')).toHaveText(name);

    // The working company is the session, not client state, so a full reload keeps it.
    await page.reload();
    await expect(page.getByTestId('company-name')).toHaveText(name);
    await expect(page.getByTestId('company-line')).toContainText(name);
  } finally {
    const operatorId = await userIdOf(page);
    await signIn(ownerPage, owner, OWNER_PASSWORD);
    const removed = await ownerPage.evaluate(
      async ([companyId, csrf, userId]) => {
        const response = await fetch(`/api/companies/${companyId}/members/${userId}`, {
          method: 'DELETE',
          headers: { 'csrf-token': csrf },
        });
        return response.status;
      },
      [setup.id, CSRF, operatorId] as const,
    );
    await theirs.close();
    expect(removed).toBe(204);
  }
});

test('the members page lists the company members', async ({ page }) => {
  await signIn(page);

  await page.getByTestId('settings-gear').click();
  await page.getByTestId('nav-members').click();
  await expect(page).toHaveURL(/\/members$/);
  await expect(page.getByTestId(`member-${EMAIL}`)).toContainText('Operator');
  await expect(page.getByTestId(`member-${EMAIL}`)).toContainText('propriétaire');
});

async function userIdOf(page: Page): Promise<string> {
  return page.evaluate(async () => {
    const me = (await (await fetch('/api/auth/me')).json()) as { user: { id: string } };
    return me.user.id;
  });
}
