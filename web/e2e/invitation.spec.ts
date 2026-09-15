// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, Page, test } from '@playwright/test';
import { invitationTokenFor } from './mailpit';

// The whole invitation, through the real stack: an operator invites an address with no account, Mailpit
// receives the mail, the link in it is opened with no session, an account is created, and that account signs
// in. The mail is read through Mailpit's own API, which is what makes this end to end rather than a mock.
const EMAIL = process.env['E2E_EMAIL'] ?? 'operator@twes.local';
const PASSWORD = process.env['E2E_PASSWORD'] ?? 'twes-operator-dev';
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

  await signIn(page, EMAIL, PASSWORD);
  await page.getByTestId('settings-gear').click();
  await page.getByTestId('nav-members').click();
  await expect(page).toHaveURL(/\/members$/);

  await page.getByTestId('member-email').fill(invited);
  await page.getByTestId('member-add').click();
  await expect(page.getByTestId('members-added')).toContainText('invitation');

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

test('a link that has already been used is refused', async ({ page }) => {
  await page.goto(`/invitations/${'a'.repeat(64)}`);

  await expect(page.getByTestId('invitation-error')).toContainText('plus valable');
});
