// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, test } from '@playwright/test';
import { inACompany, OPERATOR_PASSWORD, signIn } from './session';

/** The token every e2e sends with a write, as the others declare it. */
const CSRF = '0123456789abcdef0123456789abcdef';

/**
 * « Export comptable » is in Gérer and hands the accountant the four files of a period, last month unless changed. A
 * file leaving the company may ask for the password again; the operator's shared session may already hold the proof.
 */
test('the accountant files of a period download from « Export comptable »', async ({ page }) => {
  await signIn(page);
  await inACompany(page, CSRF);

  await page.goto('/accounting-export');
  await expect(page.getByTestId('accounting-export-title')).toBeVisible();
  for (const file of ['sales-journal', 'purchases-journal', 'payments-journal', 'vat-summary']) {
    await expect(page.getByTestId(`accounting-file-${file}`)).toBeVisible();
  }

  await page.getByTestId('accounting-export-from').fill('2026-01-01');
  await page.getByTestId('accounting-export-to').fill('2026-12-31');
  await expect(page.getByTestId('accounting-export-sales-journal-csv')).toHaveAttribute(
    'data-address',
    /\/exports\/sales-journal\.csv\?from=2026-01-01&to=2026-12-31$/,
  );

  const download = page.waitForEvent('download');
  await page.getByTestId('accounting-export-sales-journal-csv').click();
  const stepUp = page.getByTestId('step-up-password');
  const asked = await Promise.race([
    download.then(() => false),
    stepUp.waitFor({ state: 'visible' }).then(
      () => true,
      () => false,
    ),
  ]);
  if (asked) {
    await stepUp.fill(OPERATOR_PASSWORD);
    await page.getByTestId('step-up-confirm').click();
  }
  expect((await download).suggestedFilename()).toBe('sales-journal.csv');

  await page.getByTestId('accounting-export-to').fill('2025-12-31');
  await expect(page.getByTestId('accounting-export-period-error')).toBeVisible();
  await expect(page.getByTestId('accounting-export-sales-journal-csv')).toHaveCount(0);
});
