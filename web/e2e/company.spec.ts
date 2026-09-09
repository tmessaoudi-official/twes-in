// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, Page, test } from '@playwright/test';

// The G1b switcher, through the real bundle, nginx, FrankenPHP and PostgreSQL. The second company is opened
// through the API because opening one is a platform-operator action with no page of its own yet; everything
// that is asserted happens in the browser.
//
// The scenario puts the operator in a second company and takes them out again at the end. It has to: the
// seeded operator belongs to exactly one company, which is what the G1a scenario asserts, and one database
// is shared by the whole suite.
const EMAIL = process.env['E2E_EMAIL'] ?? 'operator@twes.local';
const PASSWORD = process.env['E2E_PASSWORD'] ?? 'twes-operator-dev';

// The SPA sends one random token per page load as a header; any value of the right shape is accepted, and
// the origin proof comes from the request being made by the page itself.
const CSRF = '0123456789abcdef0123456789abcdef';

async function signIn(page: Page): Promise<void> {
  await page.goto('/login');
  await page.getByTestId('email').fill(EMAIL);
  await page.getByTestId('password').fill(PASSWORD);
  await page.getByTestId('submit').click();
  await expect(page).toHaveURL(/\/$/);
}

test('the switcher moves the session to another company, and it survives a reload', async ({
  page,
}) => {
  await signIn(page);
  const name = `Globex ${Date.now()}`;

  // Joined as a plain member, not an owner: the last owner of a company can never be removed, and this
  // membership has to be removable again at the end of the test.
  const setup = await page.evaluate(
    async ([companyName, csrf, email]) => {
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
      const joined = await fetch(`/api/companies/${company.id}/members`, {
        method: 'POST',
        headers,
        body: JSON.stringify({ email, role: 'member' }),
      });
      return {
        created: created.status,
        joined: joined.status,
        status: company.status,
        id: company.id,
      };
    },
    [name, CSRF, EMAIL] as const,
  );

  expect(setup.created).toBe(201);
  expect(setup.joined).toBe(201);
  // A company an operator opens waits for its first owner; a plain member does not activate it.
  expect(setup.status).toBe('pending');

  try {
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
    const removed = await page.evaluate(
      async ([companyId, csrf, userId]) => {
        const response = await fetch(`/api/companies/${companyId}/members/${userId}`, {
          method: 'DELETE',
          headers: { 'csrf-token': csrf },
        });
        return response.status;
      },
      [setup.id, CSRF, await userIdOf(page)] as const,
    );
    expect(removed).toBe(204);
  }
});

test('the members page lists the company members', async ({ page }) => {
  await signIn(page);

  await page.getByTestId('members-link').click();
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
