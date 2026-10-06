// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test';
import { invitationTokenFor } from './mailpit';
import { inACompany, signIn as signInAsOperator } from './session';
import { toast } from './toast';

// A whole list leaving the company waits for the person at the screen to prove who they are again, and the API holds
// that proof a few minutes (docs/SPEC.md § 7, audit H-b2). The account is one of the run's own, signed in on a session
// of its own: the operator's session is shared by every spec, and another spec's proof would open the file here.
const CSRF = '0123456789abcdef0123456789abcdef';
const PASSWORD = 'a-long-enough-password';

test('a list leaves as a file only once the password was given again, and the next file asks nothing', async ({
  page,
  request,
}) => {
  const address = `exporter-${Date.now()}@twes.local`;
  await signInAsOperator(page);
  await inACompany(page, CSRF);
  await page.getByTestId('nav-settings').click();
  await page.getByTestId('nav-members').click();
  await page.getByTestId('member-email').fill(address);
  await page.getByTestId('member-add').click();
  await expect(toast(page)).toContainText('invitation');
  const invitation = await invitationTokenFor(request, address);
  await page.context().clearCookies();
  await page.goto(`/invitations/${invitation}`);
  await page.getByTestId('invitation-name').fill('Export Person');
  await page.getByTestId('invitation-password').fill(PASSWORD);
  await page.getByTestId('invitation-submit').click();
  await expect(page).toHaveURL(/\/login$/);
  await page.getByTestId('email').fill(address);
  await page.getByTestId('password').fill(PASSWORD);
  await page.getByTestId('submit').click();
  await expect(page).toHaveURL(/\/$/);

  await page.goto('/customers');
  await page.getByTestId('customers-export-csv').click();
  await expect(page.getByTestId('step-up-title')).toBeVisible();
  // It says why it asks: this list leaving, not customer view, whose question it first was.
  await expect(page.getByTestId('step-up-intro')).toContainText('télécharger cette liste');
  await page.getByTestId('step-up-password').fill('not-the-password');
  await page.getByTestId('step-up-confirm').click();
  await expect(page.getByTestId('step-up-error')).toBeVisible();
  await page.screenshot({ path: test.info().outputPath('export-step-up-asked.png') });

  const first = page.waitForEvent('download');
  await page.getByTestId('step-up-password').fill(PASSWORD);
  await page.getByTestId('step-up-confirm').click();
  expect((await first).suggestedFilename()).toBe('customers.csv');
  await expect(page.getByTestId('step-up-title')).toBeHidden();

  // Within the few minutes the proof holds, the other format is handed out without asking.
  const second = page.waitForEvent('download');
  await page.getByTestId('customers-export-xlsx').click();
  expect((await second).suggestedFilename()).toBe('customers.xlsx');
  await expect(page.getByTestId('step-up-title')).toBeHidden();
});
