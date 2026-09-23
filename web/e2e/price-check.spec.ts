// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test';
import { wcagViolations } from './axe';
import { aProduct, forget, gtin, withCodes } from './catalogue';
import { inACompany, signIn } from './session';

// docs/SPEC.md § 7, 2026-09-23 slice 6: the price check answers a code with the product and what a customer pays for
// it, taxes included, turns customer view on while it is open and gives it back as it was on leaving.
const CSRF = '0123456789abcdef0123456789abcdef';

test('the price check names a scanned product and its price, in customer view, and leaves it as it found it', async ({
  page,
}) => {
  const run = Date.now().toString(36).toUpperCase();
  const code = gtin(`201${String(Date.now() % 1_000_000_000).padStart(9, '0')}`);
  await signIn(page);
  await inACompany(page, CSRF);
  const ids: string[] = [];
  try {
    ids.push(await aProduct(page, `PC-${run}`));
    await withCodes(page, ids[0], [code]);

    await page.goto('/products');
    await page.getByTestId('price-check-link').click();
    await expect(page.getByTestId('customer-view-banner')).toBeVisible();
    await expect(page.getByTestId('price-check-waiting')).toBeVisible();

    await page.getByTestId('price-check-code').fill(code);
    await page.getByTestId('price-check-submit').click();

    await expect(page.getByTestId('price-check-name')).toHaveText(`Vis PC-${run}`);
    await expect(page.getByTestId('price-check-price')).toContainText('TND');
    await expect(page.getByTestId('price-check-pack')).toHaveCount(0);
    expect(await wcagViolations(page)).toEqual([]);

    await page.getByTestId('price-check-code').fill(`NOPE-${run}`);
    await page.getByTestId('price-check-submit').click();
    await expect(page.getByTestId('price-check-unknown')).toBeVisible();
    await expect(page.getByTestId('price-check-name')).toHaveCount(0);

    await page.getByTestId('products-tab').click();
    await expect(page.getByTestId('customer-view-banner')).toHaveCount(0);
  } finally {
    await forget(page, ids);
  }
});
