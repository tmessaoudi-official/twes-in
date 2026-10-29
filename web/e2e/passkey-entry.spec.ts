// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test';
import { inACompany, signIn } from './session';

const CSRF = '0123456789abcdef0123456789abcdef';

// Whether a company lets members in several companies enter it with a passkey is said in one place, its security page,
// beside its required second factor: the company's defaults no longer show it among the presentation choices. Read
// only, as Demo is every scenario's company: the switch is checked, not moved.
test('a company says on its security page, and only there, whether its members enter with a passkey', async ({
  page,
}) => {
  await signIn(page);
  await inACompany(page, CSRF);

  await page.goto('/company/security');
  const entry = page.getByTestId('security-passkey-entry');
  await expect(entry).toBeVisible();
  await expect(entry).toContainText('Entrer avec une clé d’accès');
  await expect(
    page.getByTestId('security-passkey-entry-toggle').getByRole('switch'),
  ).toHaveAttribute('aria-checked', 'true');

  await page.goto('/settings');
  await expect(page.getByTestId('field-document__payment_terms_days')).toBeVisible();
  await expect(page.getByTestId('field-identity__passkey_entry')).toHaveCount(0);
});
