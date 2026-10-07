// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test';
import { wcagViolations } from './axe';
import { aProduct, forget } from './catalogue';
import { inACompany, signIn } from './session';

// docs/SPEC.md § 7, 2026-09-26 23:04: « Journal d'activité », in Paramètres › Équipe, says who did what to which record
// and opens the record from its line.
const CSRF = '0123456789abcdef0123456789abcdef';

test('a product just created is in the activity journal, said in words, and opens from its line', async ({
  page,
}) => {
  const reference = `JOURNAL-${Date.now().toString(36).toUpperCase()}`;
  await signIn(page);
  await inACompany(page, CSRF);
  const ids: string[] = [];
  try {
    ids.push(await aProduct(page, reference));

    await page.goto('/company/activity?kind=product');
    await expect(page.getByTestId('activity-title')).toBeVisible();
    // Its line is found by the record it opens, since other runs create products in the same company.
    const record = page.locator(`[data-testid="activity-record"][href="/products/${ids[0]}"]`);
    await expect(record).toHaveCount(1);
    await expect(
      page.getByTestId('activity-table').getByRole('row').filter({ has: record }),
    ).toContainText('a créé l’article');
    expect(await wcagViolations(page)).toEqual([]);

    await record.click();
    await expect(page).toHaveURL(new RegExp(`/products/${ids[0]}$`));
  } finally {
    await forget(page, ids);
  }
});

test('the journal is reached from the team settings', async ({ page }) => {
  await signIn(page);
  await inACompany(page, CSRF);
  await page.goto('/members');

  await page.getByTestId('nav-activity').click();

  await expect(page).toHaveURL(/\/company\/activity/);
  await expect(page.getByTestId('activity-title')).toHaveText('Journal d’activité');
});
