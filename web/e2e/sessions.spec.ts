// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { invitationTokenFor, mailTo } from './mailpit';
import { rowMenuItem } from './rows';
import { inACompany, OPERATOR_PASSWORD, signIn as signInAsOperator } from './session';
import { proveIfAsked } from './step-up';
import { toast } from './toast';

// A member's phone is lost: the company's owner opens « Appareils connectés » on that member's row, sees the browser
// the member signed in to the company with, and signs it out. Through the real stack: the invitation is read from
// Mailpit, the member signs in from a context of their own as their phone would, and the phone finds itself signed out
// at its next request. Each run leaves one member in Demo.
const CSRF = '0123456789abcdef0123456789abcdef';
const PASSWORD = 'a-long-enough-password';
const NAME = 'Lost Phone Person';

async function signIn(page: Page, email: string, password: string): Promise<void> {
  await page.goto('/login');
  await page.getByTestId('email').fill(email);
  await page.getByTestId('login-continue').click();
  await page.getByTestId('password').fill(password);
  await page.getByTestId('submit').click();
  await expect(page).toHaveURL(/\/$/);
}

test('the owner sees where a member is signed in to the company and signs a lost phone out', async ({
  page,
  browser,
  request,
}) => {
  test.setTimeout(120_000);
  const member = `lost-phone-${Date.now()}@twes.local`;

  await signInAsOperator(page);
  await inACompany(page, CSRF);
  await page.getByTestId('nav-settings').click();
  await page.getByTestId('nav-members').click();
  await page.getByTestId('member-email').fill(member);
  await page.getByTestId('member-add').click();
  await proveIfAsked(page, OPERATOR_PASSWORD, toast(page));
  await expect(toast(page)).toContainText('invitation');

  // The member joins, then signs in on their phone: Demo is their one company, so the sign-in enters it.
  const phone = await browser.newContext();
  const phonePage = await phone.newPage();
  await phonePage.goto(`/invitations/${await invitationTokenFor(request, member)}`);
  await phonePage.getByTestId('invitation-name').fill(NAME);
  await phonePage.getByTestId('invitation-password').fill(PASSWORD);
  await phonePage.getByTestId('invitation-submit').click();
  await expect(phonePage).toHaveURL(/\/login$/);
  await signIn(phonePage, member, PASSWORD);
  await expect(phonePage.getByTestId('company-name')).toHaveText('Demo');

  await page.reload();
  await page.getByTestId('list-filter').fill(member);
  const row = page.getByTestId(`member-${member}`);
  await expect(row).toContainText(NAME);
  await row.locator('[data-testid^="row-more-"]').click();
  await rowMenuItem(page, 'devices').click();
  await expect(page.getByTestId('member-devices-title')).toContainText(NAME);
  // The one browser the member used here, and only it.
  await expect(page.getByTestId('member-device-0')).toBeVisible();
  await expect(page.getByTestId('member-device-1')).toHaveCount(0);
  await page.screenshot({ path: test.info().outputPath('member-devices.png'), fullPage: true });

  // Ending it asks the owner to prove who is at the screen, then says it done.
  await page.getByTestId('member-device-0-end').click();
  await proveIfAsked(page, OPERATOR_PASSWORD, toast(page));
  await expect(toast(page)).toContainText(`${NAME} est déconnecté de`);
  await expect(page.getByTestId('member-devices-none')).toBeVisible();

  // The phone's next request is refused: it is back at the sign-in.
  await phonePage.reload();
  await expect(phonePage).toHaveURL(/\/login/);
  await phone.close();

  // And the member is told, by mail, what their company did.
  expect(await mailTo(request, member, (subject) => !subject.startsWith('Rejoignez'))).toContain(
    'Appareil déconnecté par votre entreprise',
  );
});
