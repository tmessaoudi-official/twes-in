// SPDX-License-Identifier: AGPL-3.0-or-later
import { APIRequestContext, expect, Page, test } from '@playwright/test';

// The whole invitation, through the real stack: an operator invites an address with no account, Mailpit
// receives the mail, the link in it is opened with no session, an account is created, and that account signs
// in. The mail is read through Mailpit's own API, which is what makes this end to end rather than a mock.
const EMAIL = process.env['E2E_EMAIL'] ?? 'operator@twes.local';
const PASSWORD = process.env['E2E_PASSWORD'] ?? 'twes-operator-dev';
const MAILPIT = process.env['MAILPIT_URL'] ?? 'http://127.0.0.1:8092';
const NEW_PASSWORD = 'a-long-enough-password';

/** Polls Mailpit until the invitation for that address has arrived, and returns the raw token from its link. */
async function tokenFromMailpit(request: APIRequestContext, to: string): Promise<string> {
  for (let attempt = 0; attempt < 20; attempt += 1) {
    const listed = await request.get(`${MAILPIT}/api/v1/messages`);
    const { messages } = (await listed.json()) as {
      messages: { ID: string; To: { Address: string }[] }[];
    };
    const mine = messages.find((message) => message.To.some((address) => address.Address === to));
    if (mine) {
      const body = (await (await request.get(`${MAILPIT}/api/v1/message/${mine.ID}`)).json()) as {
        HTML?: string;
      };
      const found = /\/invitations\/([0-9a-f]{64})/.exec(String(body.HTML ?? ''));
      if (found) {
        return found[1];
      }
    }
    await new Promise((resolve) => setTimeout(resolve, 500));
  }
  throw new Error(`no invitation mail for ${to} arrived at Mailpit`);
}

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
  await page.getByTestId('nav-members').click();
  await expect(page).toHaveURL(/\/members$/);

  await page.getByTestId('member-email').fill(invited);
  await page.getByTestId('member-add').click();
  await expect(page.getByTestId('members-added')).toContainText('invitation');

  // The mail really went out: Mailpit has it, and it carries a usable link.
  const token = await tokenFromMailpit(request, invited);

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
