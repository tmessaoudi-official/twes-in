// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test, type Page } from '@playwright/test';
import { wcagViolations } from './axe';
import { aProduct, forget, gtin, withCodes } from './catalogue';
import { scan } from './scan';
import { inACompany, signIn } from './session';
import { toast } from './toast';

// docs/SPEC.md § 7, 2026-09-23 slice 7: lots on the web. A product set to be kept by lot asks its lot when goods of
// it are received, and the stock list names the lot the goods went into.
const CSRF = '0123456789abcdef0123456789abcdef';

/** Keeps stock of the product, or stops keeping it, through the product's own setting. */
async function stockKept(page: Page, productId: string, kept: boolean): Promise<void> {
  await page.evaluate(
    async ([csrf, id, on]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}/settings/article.stock_tracking`;
      const response = on
        ? await fetch(base, {
            method: 'PUT',
            headers: { 'content-type': 'application/json', 'csrf-token': csrf },
            body: JSON.stringify({ level: 'product', value: true, productId: id }),
          })
        : await fetch(`${base}?level=product&productId=${id}`, {
            method: 'DELETE',
            headers: { 'csrf-token': csrf },
          });
      if (!response.ok) throw new Error(`the stock setting answered ${response.status}`);
    },
    [CSRF, productId, kept] as const,
  );
}

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
    await page.getByTestId('stock-movement-save').click();
    await expect(page.getByRole('row').filter({ hasText: scannedLot })).toContainText('2027-05-31');
  } finally {
    if (ids.length > 0) await stockKept(page, ids[0], false);
    await forget(page, ids);
  }
});
