// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, test } from '@playwright/test';
import { inACompany, signIn } from './session';

/** The token every e2e sends with a write, as the others declare it. */
const CSRF = '0123456789abcdef0123456789abcdef';

/**
 * The late fee is the company's to turn on and to price: the settings page offers it beside the reminder calendar,
 * says no amount is proposed, and refuses tiers it could not read. Nothing is saved: the shared company keeps its own.
 */
test('the late fee is offered beside the reminders, with no amount proposed', async ({ page }) => {
  await signIn(page);
  await inACompany(page, CSRF);

  await page.goto('/settings');
  await expect(page.getByTestId('field-reminders__stages')).toBeVisible();
  await expect(page.getByTestId('field-late_fees__enabled')).toBeVisible();
  const tiers = page.getByTestId('field-late_fees__tiers');
  await expect(tiers).toBeVisible();
  await expect(page.getByText('Aucun montant n’est proposé par défaut.')).toBeVisible();

  const error = page.getByTestId('field-error-late_fees__tiers');
  await tiers.fill('cinq dinars');
  await tiers.blur();
  await expect(error).toBeVisible();

  await tiers.fill('5 ; 10,5 ; 2 %');
  await tiers.blur();
  await expect(error).toHaveCount(0);
});
