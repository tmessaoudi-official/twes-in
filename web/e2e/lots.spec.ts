// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test';
import { wcagViolations } from './axe';
import { aProduct, forget, gtin, stockKept, withCodes } from './catalogue';
import { scan } from './scan';
import { inACompany, signIn } from './session';
import { toast } from './toast';

// docs/SPEC.md § 7, 2026-09-23 slice 7: lots on the web. A product set to be kept by lot asks its lot when goods of
// it are received, and the stock list names the lot the goods went into.
const CSRF = '0123456789abcdef0123456789abcdef';

test('a product kept by lot asks its lot on receipt, a GS1 label fills it, and the stock list names it', async ({
  page,
}) => {
  const run = Date.now().toString(36).toUpperCase();
  const reference = `LOT-${run}`;
  const lot = `L-${run}`;
  const scannedLot = `S-${run}`;
  const code = gtin(`203${String(Date.now() % 1_000_000_000).padStart(9, '0')}`);
  await signIn(page);
  await inACompany(page, CSRF);
  const ids: string[] = [];
  try {
    ids.push(await aProduct(page, reference));
    await stockKept(page, ids[0], true);
    await withCodes(page, ids[0], [code]);

    await page.goto(`/products/${ids[0]}`);
    await page.getByTestId('field-tracking').click();
    await page.getByRole('option', { name: 'Par lot' }).click();
    await page.getByTestId('record-save').click();
    await expect(toast(page)).toBeVisible();

    await page.goto('/stock');
    await page.getByTestId('stock-receive').click();
    await expect(page.getByTestId('field-lotCode')).toHaveCount(0);
    await page.getByTestId('field-productId').fill(reference);
    await page.getByRole('option', { name: `${reference} · Vis ${reference}` }).click();
    await page.getByTestId('field-lotCode').fill(lot);
    await page.getByTestId('field-quantity').fill('3');
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('stock-movement-save').click();
    await expect(toast(page)).toContainText('Le mouvement a été enregistré.');

    await page.getByTestId('list-filter').fill(reference);
    const row = page.getByRole('row').filter({ hasText: lot });
    await expect(row).toHaveCount(1);
    await expect(row).toContainText(reference);
    expect(await wcagViolations(page)).toEqual([]);

    // A GS1 label scanned on an open receipt fills the product, its lot and the day it is used by.
    await page.getByTestId('stock-receive').click();
    await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur());
    await scan(page, `]C1010${code}1727053110${scannedLot}`);
    await expect(page.getByTestId('field-lotCode')).toHaveValue(scannedLot);
    await expect(page.getByTestId('field-lotExpiresOn')).toHaveValue('2027-05-31');
    await expect(page.getByTestId('field-quantity')).toHaveValue('1');
    // The same label again counts on, as a till does.
    await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur());
    await scan(page, `]C1010${code}1727053110${scannedLot}`);
    await expect(page.getByTestId('field-quantity')).toHaveValue('2');
    await page.getByTestId('stock-movement-save').click();
    await expect(page.getByRole('row').filter({ hasText: scannedLot })).toContainText('2027-05-31');

    // Row 63 slice 10: a recall starts from the code, typed or read from the label, and finds what moved it.
    await page.goto('/stock/movements');
    await page.getByTestId('stock-movements-lot-search').fill(lot.toLowerCase());
    await page.getByTestId('stock-movements-lot-search').press('Enter');
    await expect(page).toHaveURL(new RegExp(`lot=${lot.toLowerCase()}`));
    await expect(page.getByTestId('stock-movements-of-lot')).toContainText(lot.toLowerCase());
    await expect(page.locator('[data-column="lot"]').filter({ hasText: lot })).toHaveCount(1);
    await expect(page.locator('[data-column="lot"]').filter({ hasText: scannedLot })).toHaveCount(
      0,
    );
    expect(await wcagViolations(page)).toEqual([]);
    await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur());
    await scan(page, `]C1010${code}1727053110${scannedLot}`);
    await expect(page).toHaveURL(new RegExp(`lot=${scannedLot}`));
    await expect(page.locator('[data-column="lot"]').filter({ hasText: scannedLot })).toHaveCount(
      1,
    );

    // Row 108: a delivery note's line names the lot it hands over, and the label fills it.
    await page.goto('/delivery-notes/new');
    await expect(page.getByTestId('line-0-description')).toBeVisible();
    await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur());
    await scan(page, `]C1010${code}1727053110${scannedLot}`);
    await expect(page.getByTestId('line-0-lot')).toHaveValue(scannedLot);
    await expect(page.getByTestId('line-0-quantity')).toHaveValue('1');
    expect(await wcagViolations(page)).toEqual([]);

    // And an invoice's line names the lot it sells, from the same label.
    await page.goto('/invoices/new');
    await expect(page.getByTestId('line-0-description')).toBeVisible();
    await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur());
    await scan(page, `]C1010${code}1727053110${scannedLot}`);
    await expect(page.getByTestId('line-0-lot')).toHaveValue(scannedLot);
    expect(await wcagViolations(page)).toEqual([]);

    // Row 110: the product keeps a reorder point per establishment, beside where it is stored.
    await page.goto(`/products/${ids[0]}`);
    await page.getByRole('tab', { name: 'Où il est rangé' }).click();
    const quantity = page.locator('[data-testid^="product-reorder-quantity-"]').first();
    await expect(quantity).toHaveValue('');
    // A piece is counted whole: the API refuses a half, and the section says so.
    await quantity.fill('2,5');
    await page.locator('[data-testid^="product-reorder-save-"]').first().click();
    await expect(page.getByTestId('product-reorder-error')).toBeVisible();
    await quantity.fill('4');
    await page.locator('[data-testid^="product-reorder-save-"]').first().click();
    await expect(toast(page)).toContainText('Le seuil a été enregistré.');
    expect(await wcagViolations(page)).toEqual([]);
    await page.reload();
    await page.getByRole('tab', { name: 'Où il est rangé' }).click();
    await expect(page.locator('[data-testid^="product-reorder-quantity-"]').first()).toHaveValue(
      '4',
    );
  } finally {
    if (ids.length > 0) await stockKept(page, ids[0], false);
    await forget(page, ids);
  }
});
