// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, test } from '@playwright/test';
import { invitationTokenFor } from './mailpit';
import { OPERATOR_PASSWORD, inACompany, signIn as signInAsOperator } from './session';
import { proveIfAsked } from './step-up';
import { toast } from './toast';

const CSRF = '0123456789abcdef0123456789abcdef';
const PASSWORD = 'a-long-enough-password';

/**
 * An admin is offered on « Membres » only what the API lets an admin do (hunt N-1): nothing on an owner's row, no role
 * change on their own, and never « propriétaire » to give. The account is one of the run's own, on a session of its
 * own, since the operator's session is shared by every spec.
 */
test('an admin is offered nothing on an owner’s row and never the owner role', async ({
  page,
  request,
}) => {
  const address = `admin-${Date.now()}@twes.local`;
  await signInAsOperator(page);
  await inACompany(page, CSRF);
  await page.getByTestId('nav-settings').click();
  await page.getByTestId('nav-members').click();
  await page.getByTestId('member-email').fill(address);
  await page.getByTestId('member-role').click();
  await page.getByRole('option', { name: /administrateur/i }).click();
  await page.getByTestId('member-add').click();
  await proveIfAsked(page, OPERATOR_PASSWORD, toast(page));
  await expect(toast(page)).toContainText('invitation');
  const invitation = await invitationTokenFor(request, address);

  await page.context().clearCookies();
  await page.goto(`/invitations/${invitation}`);
  await page.getByTestId('invitation-name').fill('Admin Invité');
  await page.getByTestId('invitation-password').fill(PASSWORD);
  await page.getByTestId('invitation-submit').click();
  await expect(page).toHaveURL(/\/login$/);
  await page.getByTestId('email').fill(address);
  await page.getByTestId('login-continue').click();
  await page.getByTestId('password').fill(PASSWORD);
  await page.getByTestId('submit').click();
  await expect(page).toHaveURL(/\/$/);
  await inACompany(page, CSRF);

  await page.getByTestId('nav-settings').click();
  await page.getByTestId('nav-members').click();
  const ownerRow = page.getByTestId('member-operator@twes.local');
  await expect(ownerRow).toBeVisible();
  await expect(
    ownerRow.locator(
      '[data-testid^="row-action-remove-"], [data-testid^="row-action-change-role-"]',
    ),
  ).toHaveCount(0);
  const more = ownerRow.locator('[data-testid^="row-more-"]');
  if ((await more.count()) > 0) {
    await more.click();
    await expect(
      page.locator('[data-testid^="row-menu-remove-"], [data-testid^="row-menu-change-role-"]'),
    ).toHaveCount(0);
    await page.keyboard.press('Escape');
  }

  await page.getByTestId('member-role').click();
  await expect(page.getByRole('option', { name: /administrateur/i })).toBeVisible();
  await expect(page.getByRole('option', { name: /propriétaire/i })).toHaveCount(0);
});
