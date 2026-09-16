// SPDX-License-Identifier: AGPL-3.0-or-later
import AxeBuilder from '@axe-core/playwright';
import { expect, Page, test } from '@playwright/test';
import { invitationTokenFor } from './mailpit';
import { OPERATOR_EMAIL as EMAIL, signIn, signInWithCode } from './session';

// The G1b switcher, through the real bundle, nginx, FrankenPHP and PostgreSQL. The second company is opened by
// the operator on /platform; what the owner and the operator then do between them goes through the API.
//
// The scenario puts the operator in a second company and has that company's owner take them out again at the
// end. It has to: the seeded operator belongs to exactly one company, which is what the G1a scenario asserts,
// and one database is shared by the whole suite. The switch moves a session, so the operator signs in on one of
// their own rather than the one every other scenario shares.

// The SPA sends one random token per page load as a header; any value of the right shape is accepted, and
// the origin proof comes from the request being made by the page itself.
const CSRF = '0123456789abcdef0123456789abcdef';
const OWNER_PASSWORD = 'a-long-enough-password';

// docs/SPEC.md § 8 row 23 (review C8). The invitation screen is checked here rather than in the central walk of
// accessibility.spec.ts because only this scenario holds a live token: it opens the company, invites its owner and
// reads the link out of mailpit. A walk elsewhere would have to build that fixture a second time.
async function wcagViolations(page: Page): Promise<string[]> {
  const axe = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
    .analyze();
  return axe.violations.map((violation) => violation.id);
}

async function signInAs(page: Page, email: string, password: string): Promise<void> {
  await page.goto('/login');
  await page.getByTestId('email').fill(email);
  await page.getByTestId('password').fill(password);
  await page.getByTestId('submit').click();
  await expect(page).toHaveURL(/\/$/);
}

test('an operator opens a company from the platform, and the switcher moves the session to it', async ({
  page,
  browser,
  request,
}) => {
  test.setTimeout(150_000);
  await signInWithCode(page);
  const name = `Globex ${Date.now()}`;
  const owner = `globex-owner-${Date.now()}@twes.local`;

  // The operator opens the company and invites its first owner from the platform page, never joining it (C7).
  await page.goto('/platform');
  await page.getByTestId('platform-company-name').fill(name);
  await page.getByTestId('platform-company-country').selectOption('TN');
  await page.getByTestId('platform-company-owner').fill(owner);
  await page.getByTestId('platform-company-create').click();
  await expect(page.getByTestId('platform-company-invited')).toContainText(owner);
  // A company an operator opens waits for its first owner.
  await expect(page.getByTestId(`company-${name}`)).toContainText('En attente');
  await page.getByTestId(`company-${name}`).scrollIntoViewIfNeeded();
  await page.screenshot({ path: test.info().outputPath('platform-company-opened.png') });

  // The owner is somebody else, in a browser of their own; accepting makes their account and opens the company.
  const theirs = await browser.newContext();
  const ownerPage = await theirs.newPage();
  await ownerPage.goto(`/invitations/${await invitationTokenFor(request, owner)}`);
  await expect(ownerPage.getByTestId('invitation-name')).toBeVisible();
  expect(await wcagViolations(ownerPage)).toEqual([]);
  await ownerPage.getByTestId('invitation-name').fill('Globex Owner');
  await ownerPage.getByTestId('invitation-password').fill(OWNER_PASSWORD);
  await ownerPage.getByTestId('invitation-submit').click();
  await expect(ownerPage).toHaveURL(/\/login$/);
  await signInAs(ownerPage, owner, OWNER_PASSWORD);
  await expect(ownerPage.getByTestId('company-name')).toHaveText(name);

  // A membership is what opens a company, so the owner invites the operator as a plain member, and takes them out
  // again at the end: a member removes nobody, and the seeded operator belongs to Demo alone in every other scenario.
  const companyId = await ownerPage.evaluate(
    async () =>
      ((await (await fetch('/api/auth/me')).json()) as { company: { id: string } }).company.id,
  );
  const invited = await ownerPage.evaluate(
    async ([id, csrf, email]) =>
      (
        await fetch(`/api/companies/${id}/members`, {
          method: 'POST',
          headers: { 'content-type': 'application/json', 'csrf-token': csrf },
          body: JSON.stringify({ email, role: 'member' }),
        })
      ).status,
    [companyId, CSRF, EMAIL] as const,
  );
  expect(invited).toBe(201);

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

    await page.goto('/');
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
    const removed = await ownerPage.evaluate(
      async ([id, csrf, userId]) => {
        const response = await fetch(`/api/companies/${id}/members/${userId}`, {
          method: 'DELETE',
          headers: { 'csrf-token': csrf },
        });
        return response.status;
      },
      [companyId, CSRF, operatorId] as const,
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
