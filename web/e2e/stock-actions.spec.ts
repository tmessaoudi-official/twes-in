// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test';
import { inACompany, signIn } from './session';
import { wcagViolations } from './axe';
import { aProduct, forget, receivedAt, stockKept } from './catalogue';
import { rowMenuItem } from './rows';
import { runDigits } from './run';

// Round 2: every action where its subject appears. A product's stock is moved from the product's own page and from
// the row showing it at a place, each opening Stock's form already naming it; nothing is recorded until saved there.
// The product is unique to the run and retired afterwards; the one piece received stays in the shared company's
// movements, as every stock scenario's does.
const CSRF = '0123456789abcdef0123456789abcdef';

test('a product is counted from its own page and from its row of stock, the form naming it', async ({
  page,
}) => {
  const reference = `E2E-SA-${runDigits()}`;
  await signIn(page);
  await inACompany(page, CSRF);
  const productId = await aProduct(page, reference);
  try {
    await stockKept(page, productId, true);
    await receivedAt(page, productId, '1');

    await page.goto(`/products/${productId}`);
    const offered = page.getByTestId('record-stock_count');
    await expect(offered.or(page.getByTestId('record-more')).first()).toBeVisible();
    if (!(await offered.isVisible())) {
      await page.getByTestId('record-more').click();
      await page.getByTestId('record-menu-stock_count').click();
    } else {
      await offered.click();
    }
    await expect(page).toHaveURL(new RegExp(`/stock\\?operation=count&product=${productId}$`));
    await expect(page.getByTestId('field-productId')).toHaveValue(new RegExp(reference));
    expect(await wcagViolations(page)).toEqual([]);
    await page.screenshot({ path: test.info().outputPath('count-from-product.png') });
    await page.getByTestId('stock-movement-cancel').click();
    await expect(page).not.toHaveURL(/operation=/);

    // The list kept the product the address named: its row at the place the piece was received offers the count.
    const row = page.locator(`[data-testid^="stock-${reference}-"]`).first();
    await expect(row).toBeVisible();
    await row.locator('[data-testid^="row-more-"]').click();
    await rowMenuItem(page, 'count_here').click();
    await expect(page).toHaveURL(/operation=count/);
    await expect(page.getByTestId('field-productId')).toHaveValue(new RegExp(reference));
    await expect(page).toHaveURL(/location=[0-9a-f-]{36}/);
    // Nothing was recorded by opening it: leaving it puts the list back as it was.
    await page.getByTestId('stock-movement-cancel').click();
    await expect(page).not.toHaveURL(/operation=/);
  } finally {
    await forget(page, [productId]);
  }
});
