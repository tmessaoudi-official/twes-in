// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, Page, test } from '@playwright/test';
import { invitationTokenFor } from './mailpit';
import { signIn as signInAsOperator } from './session';
import { toast } from './toast';

// The whole invitation, through the real stack: an operator invites an address with no account, Mailpit
// receives the mail, the link in it is opened with no session, an account is created, and that account signs
// in. The mail is read through Mailpit's own API, which is what makes this end to end rather than a mock.
const NEW_PASSWORD = 'a-long-enough-password';
const CSRF = '0123456789abcdef0123456789abcdef';

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
  await page.getByTestId('settings-gear').click();
  await page.getByTestId('nav-members').click();
  await expect(page).toHaveURL(/\/members$/);

  await page.getByTestId('member-email').fill(invited);
  await page.getByTestId('member-add').click();
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
  const opened = await page.evaluate(
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
      const company = (await created.json()) as { id: string };
      const invited = await fetch(`/api/platform/companies/${company.id}/owners`, {
        method: 'POST',
        headers,
        body: JSON.stringify({ email }),
      });
      return [created.status, invited.status];
    },
    [`Initech ${Date.now()}`, CSRF, existing] as const,
  );
  expect(opened).toEqual([201, 201]);
  const theirs = await browser.newContext();
  const theirPage = await theirs.newPage();
  await theirPage.goto(`/invitations/${await invitationTokenFor(request, existing)}`);
  await theirPage.getByTestId('invitation-name').fill('Existing Person');
  await theirPage.getByTestId('invitation-password').fill(NEW_PASSWORD);
  await theirPage.getByTestId('invitation-submit').click();
  await expect(theirPage).toHaveURL(/\/login$/);

  // Inviting that address into Demo is inviting any address: until the link is used it is not a member.
  await page.getByTestId('settings-gear').click();
  await page.getByTestId('nav-members').click();
  await page.getByTestId('member-email').fill(existing);
  await page.getByTestId('member-add').click();
  await expect(toast(page)).toContainText('invitation');
  // Every scenario adds people to Demo, so the row is found by filtering rather than by the page it lands on.
  await page.getByTestId('list-filter').fill(existing);
  await expect(page.getByTestId(`member-${existing}`)).toContainText('Invitation en attente');
  await expect(page.getByTestId(`remove-${existing}`)).toHaveCount(0);
  await page.screenshot({
    path: test.info().outputPath('members-invitation-pending.png'),
    fullPage: true,
  });

  await theirPage.goto(`/invitations/${await invitationTokenFor(request, existing)}`);
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
});

test('a link that has already been used is refused', async ({ page }) => {
  await page.goto(`/invitations/${'a'.repeat(64)}`);

  await expect(page.getByTestId('invitation-error')).toContainText('plus valable');
});
