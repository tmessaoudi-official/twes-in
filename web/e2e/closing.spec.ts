// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, test } from '@playwright/test';
import { inACompany, signIn } from './session';

/** The token every e2e sends with a write, as the others declare it. */
const CSRF = '0123456789abcdef0123456789abcdef';

/**
 * « Clôture des comptes » says what is closed and asks before closing more, saying it is final. The shared company is
 * never closed here: a closed period never opens again, so the case answers the question no.
 */
test('closing the books asks first and says it cannot be taken back', async ({ page }) => {
  await signIn(page);
  await inACompany(page, CSRF);

  await page.goto('/company/closing');
  await expect(page.getByTestId('closing-state')).toBeVisible();
  const day = page.getByTestId('closing-day');
  const latest = await day.getAttribute('max');
  expect(latest).toMatch(/^\d{4}-\d{2}-\d{2}$/);

  await day.fill(latest ?? '');
  await page.getByTestId('closing-close').click();
  await expect(page.getByTestId('confirm-kind')).toHaveAttribute('data-kind', 'definitif');
  await page.getByTestId('confirm-keep').click();

  await expect(page.getByTestId('confirm-kind')).toHaveCount(0);
  // The field shows the day as the company writes it, day first, and keeps it after « keep ».
  const [year, month, dayOfMonth] = (latest ?? '').split('-');
  await expect(page.getByTestId('closing-day')).toHaveValue(`${dayOfMonth}/${month}/${year}`);
});
