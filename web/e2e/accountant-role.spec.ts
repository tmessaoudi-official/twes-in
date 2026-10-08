// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, test } from '@playwright/test';
import { invitationTokenFor } from './mailpit';
import { inACompany, signIn as signInAsOperator } from './session';
import { toast } from './toast';

const CSRF = '0123456789abcdef0123456789abcdef';
const PASSWORD = 'a-long-enough-password';

/**
 * The built-in accountant, « Comptable (lecture seule) »: invited like any member, they find the invoices and
 * « Export comptable » in the menu, and neither the catalogue nor the team. The account is one of the run's own, on a
 * session of its own, since the operator's session is shared by every spec.
 */
test('an accountant invited to the company reads the books and takes their files, and nothing else', async ({
  page,
  request,
}) => {
  const address = `accountant-${Date.now()}@twes.local`;
  await signInAsOperator(page);
  await inACompany(page, CSRF);
  await page.getByTestId('nav-settings').click();
  await page.getByTestId('nav-members').click();
  await page.getByTestId('member-email').fill(address);
  await page.getByTestId('member-role').click();
  await page.getByRole('option', { name: /comptable/i }).click();
  await page.getByTestId('member-add').click();
  await expect(toast(page)).toContainText('invitation');
  const invitation = await invitationTokenFor(request, address);

  await page.context().clearCookies();
  await page.goto(`/invitations/${invitation}`);
  await page.getByTestId('invitation-name').fill('Comptable Externe');
  await page.getByTestId('invitation-password').fill(PASSWORD);
  await page.getByTestId('invitation-submit').click();
  await expect(page).toHaveURL(/\/login$/);
  await page.getByTestId('email').fill(address);
  await page.getByTestId('password').fill(PASSWORD);
  await page.getByTestId('submit').click();
  await expect(page).toHaveURL(/\/$/);

  await expect(page.getByTestId('nav-invoices')).toBeVisible();
  await expect(page.getByTestId('nav-accounting_export')).toBeVisible();
  await expect(page.getByTestId('nav-products')).toHaveCount(0);

  await page.getByTestId('nav-accounting_export').click();
  await expect(page).toHaveURL(/\/accounting-export$/);
  await expect(page.getByTestId('accounting-file-vat-summary')).toBeVisible();
  await page.screenshot({ path: test.info().outputPath('accountant-role.png') });
});
