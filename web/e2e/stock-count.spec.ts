// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test';
import { wcagViolations } from './axe';
import { aProduct, forget, gtin, stockKept, withCodes } from './catalogue';
import { scan } from './scan';
import { inACompany, signIn } from './session';
import { toast } from './toast';

// docs/SPEC.md § 7, 2026-09-23 slice 8: count mode. What is scanned at a location is tallied there, and recording sets
// that location's stock of it to what was counted.
const CSRF = '0123456789abcdef0123456789abcdef';

test('count mode tallies what is scanned at a location and records it as the stock there', async ({
  page,
}) => {
  const run = Date.now().toString(36).toUpperCase();
  const reference = `CNT-${run}`;
  const code = gtin(`204${String(Date.now() % 1_000_000_000).padStart(9, '0')}`);
  await signIn(page);
  await inACompany(page, CSRF);
  const ids: string[] = [];
  try {
    ids.push(await aProduct(page, reference));
    await stockKept(page, ids[0], true);
    await withCodes(page, ids[0], [code]);

    await page.goto('/stock');
    await page.getByTestId('stock-count-tab').click();
    await expect(page.getByTestId('stock-count-empty')).toBeVisible();
    await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur());
    await scan(page, code);
    await expect(page.getByTestId('stock-count-0-counted')).toHaveValue('1');
    await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur());
    await scan(page, code);
    await expect(page.getByTestId('stock-count-0-counted')).toHaveValue('2');
    await expect(page.getByTestId('stock-count-1')).toHaveCount(0);
    expect(await wcagViolations(page)).toEqual([]);

    await page.getByTestId('stock-count-record').click();
    await expect(toast(page)).toContainText('Comptage enregistré');
    await expect(page.getByTestId('stock-count-empty')).toBeVisible();

    await page.getByTestId('stock-tab').click();
    await page.getByTestId('list-filter').fill(reference);
    const row = page.getByRole('row').filter({ hasText: reference });
    await expect(row.locator('[data-column="quantity"]')).toHaveText('2');
  } finally {
    if (ids.length > 0) await stockKept(page, ids[0], false);
    await forget(page, ids);
  }
});
