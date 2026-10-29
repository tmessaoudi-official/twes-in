// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, Page, test } from '@playwright/test';
import { invitationTokenFor } from './mailpit';
import { OPERATOR_PASSWORD, signIn as signInAsOperator } from './session';
import { proveIfAsked, sendProving } from './step-up';
import { toast } from './toast';

// The whole invitation, through the real stack: an operator invites an address with no account, Mailpit
// receives the mail, the link in it is opened with no session, an account is created, and that account signs
// in. The mail is read through Mailpit's own API, which is what makes this end to end rather than a mock.
const NEW_PASSWORD = 'a-long-enough-password';

async function signIn(page: Page, email: string, password: string): Promise<void> {
  await page.goto('/login');
  await page.getByTestId('email').fill(email);
  await page.getByTestId('password').fill(password);
  await page.getByTestId('submit').click();
  await expect(page).toHaveURL(/\/$/);
}

test('an invited address sets a password from the mailed link and then signs in', async ({
  page,
  request,
}) => {
  const invited = `invited-${Date.now()}@twes.local`;

  await signInAsOperator(page);
  await page.getByTestId('nav-settings').click();
  await page.getByTestId('nav-members').click();
  await expect(page).toHaveURL(/\/members$/);

  await page.getByTestId('member-email').fill(invited);
  await page.getByTestId('member-add').click();
  await proveIfAsked(page, OPERATOR_PASSWORD, toast(page));
  await expect(toast(page)).toContainText('invitation');

  // The mail really went out: Mailpit has it, and it carries a usable link.
  const token = await invitationTokenFor(request, invited);

  // The link arrives from a mail client, so it is opened with no session at all.
  await page.context().clearCookies();

  await page.goto(`/invitations/${token}`);
  await expect(page.getByTestId('invitation-offer')).toContainText('Demo');
  await expect(page.getByTestId('invitation-email')).toHaveText(invited);

  await page.getByTestId('invitation-name').fill('Invited Person');
  await page.getByTestId('invitation-password').fill(NEW_PASSWORD);
  await page.getByTestId('invitation-submit').click();

  // Accepting makes the account; it deliberately does not sign anybody in.
  await expect(page).toHaveURL(/\/login$/);

  await signIn(page, invited, NEW_PASSWORD);
  await expect(page.getByTestId('greeting')).toContainText('Invited Person');
  await expect(page.getByTestId('company-name')).toHaveText('Demo');
});

test('an address that already has an account joins from the mailed link with nothing to fill in', async ({
  page,
  browser,
  request,
}) => {
  test.setTimeout(90_000);
  const existing = `existing-${Date.now()}@twes.local`;

  // The account comes first: the owner of a company of its own, invited the way an operator opens one.
  await signInAsOperator(page);
  // Opening a company and every platform write wait for the operator's recent proof (docs/SPEC.md § 7), given
  // only when asked: every attempt counts against the operator's five in five minutes.
  const created = await sendProving(page, OPERATOR_PASSWORD, 'POST', '/api/companies', {
    name: `Initech ${Date.now()}`,
    countryCode: 'TN',
    currency: 'TND',
    locale: 'fr',
    timezone: 'Africa/Tunis',
  });
  expect(created.status).toBe(201);
  const owners = `/api/platform/companies/${(created.body as { id: string }).id}/owners`;
  expect(
    (await sendProving(page, OPERATOR_PASSWORD, 'POST', owners, { email: existing })).status,
  ).toBe(201);
  const theirs = await browser.newContext();
  const theirPage = await theirs.newPage();
  const firstToken = await invitationTokenFor(request, existing);
  await theirPage.goto(`/invitations/${firstToken}`);
  await theirPage.getByTestId('invitation-name').fill('Existing Person');
  await theirPage.getByTestId('invitation-password').fill(NEW_PASSWORD);
  await theirPage.getByTestId('invitation-submit').click();
  await expect(theirPage).toHaveURL(/\/login$/);

  // Inviting that address into Demo is inviting any address: until the link is used it is not a member.
  await page.getByTestId('nav-settings').click();
  await page.getByTestId('nav-members').click();
  await page.getByTestId('member-email').fill(existing);
  await page.getByTestId('member-add').click();
  await proveIfAsked(page, OPERATOR_PASSWORD, toast(page));
  await expect(toast(page)).toContainText('invitation');
  // Every scenario adds people to Demo, so the row is found by filtering rather than by the page it lands on.
  await page.getByTestId('list-filter').fill(existing);
  await expect(page.getByTestId(`member-${existing}`)).toContainText('Invitation en attente');
  await expect(page.getByTestId(`remove-${existing}`)).toHaveCount(0);
  await page.screenshot({
    path: test.info().outputPath('members-invitation-pending.png'),
    fullPage: true,
  });

  await theirPage.goto(`/invitations/${await invitationTokenFor(request, existing, firstToken)}`);
  await expect(theirPage.getByTestId('invitation-has-account')).toBeVisible();
  await expect(theirPage.getByTestId('invitation-name')).toHaveCount(0);
  await expect(theirPage.getByTestId('invitation-password')).toHaveCount(0);
  await theirPage.screenshot({
    path: test.info().outputPath('invitation-existing-account.png'),
    fullPage: true,
  });
  await theirPage.getByTestId('invitation-join').click();
  await expect(theirPage).toHaveURL(/\/login$/);

  // Their own password still works, and Demo is now one of their companies.
  await signIn(theirPage, existing, NEW_PASSWORD);
  await theirPage.getByTestId('company-switcher').click();
  await expect(theirPage.getByTestId('company-option-Demo')).toBeVisible();
  await theirs.close();

  // A member's role changes where they are listed, from a dialog, and the toast offers it back (hunt F5).
  const row = page.getByTestId(`member-${existing}`);
  await expect(row).toContainText('Membre');
  await row.locator('[data-testid^="row-more-"]').click();
  await page.locator('[data-testid^="row-menu-change-role-"]').click();
  await expect(page.getByTestId('member-role-title')).toContainText('Existing Person');
  await page.getByTestId('member-role-change').click();
  await page.getByRole('option', { name: 'Administrateur' }).click();
  await page.getByTestId('member-role-confirm').click();
  await proveIfAsked(page, OPERATOR_PASSWORD, toast(page));
  await expect(toast(page)).toContainText('Existing Person est maintenant administrateur.');
  await expect(row).toContainText('Administrateur');
});

// The page says a company the platform refused, and why, before anything is filled in: signing in would say it only
// after the account was made. Each run leaves one refused company behind.
test('the invited owner of a company the platform refused reads it, and why, before the form', async ({
  page,
  browser,
  request,
}) => {
  test.setTimeout(90_000);
  const stamp = Date.now();
  const owner = `refused-owner-${stamp}@twes.local`;
  const name = `Refusée ${stamp}`;
  const reason = 'Matricule fiscal introuvable';

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
  const invited = await sendProving(
    page,
    OPERATOR_PASSWORD,
    'POST',
    `/api/platform/companies/${companyId}/owners`,
    { email: owner },
  );
  expect(invited.status).toBe(201);
  const rejected = await sendProving(
    page,
    OPERATOR_PASSWORD,
    'POST',
    `/api/platform/companies/${companyId}/reject`,
    { reason },
  );
  expect(rejected.status).toBe(200);

  // The link arrives in a mail client: opened with no session at all.
  const theirs = await browser.newContext();
  const theirPage = await theirs.newPage();
  await theirPage.goto(`/invitations/${await invitationTokenFor(request, owner)}`);
  const closed = theirPage.getByTestId('invitation-company-closed');
  await expect(closed).toContainText(`${name} n’a pas été acceptée par l’équipe de la plateforme`);
  await expect(theirPage.getByTestId('invitation-company-reason')).toContainText(reason);
  // Said above the form, which stays: the person may still make the account.
  const above = await closed.evaluate(
    (notice) =>
      document.querySelector('[data-testid="invitation-name"]') !== null &&
      (notice.compareDocumentPosition(
        document.querySelector('[data-testid="invitation-name"]') as Node,
      ) &
        Node.DOCUMENT_POSITION_FOLLOWING) !==
        0,
  );
  expect(above).toBe(true);
  await theirs.close();
});

test('a link that has already been used is refused', async ({ page }) => {
  await page.goto(`/invitations/${'a'.repeat(64)}`);

  await expect(page.getByTestId('invitation-error')).toContainText('plus valable');
});
