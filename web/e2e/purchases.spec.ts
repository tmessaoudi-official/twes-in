// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { wcagViolations } from './axe';
import { aProduct, forget, stockKept } from './catalogue';
import { inACompany, signIn } from './session';
import { toast } from './toast';

// An order to a vendor through the real stack: a draft started from the vendor, sent, its goods received in two parts,
// and the stock list showing what came. One database is shared by the whole suite, so every name is unique to the run;
// the order stays (an order received is a record), the product and the vendor are retired at the end.
const CSRF = '0123456789abcdef0123456789abcdef';

/** Creates the run's vendor through the API; its id. */
async function aVendor(page: Page, number: string): Promise<string> {
  return page.evaluate(
    async ([csrf, vendorNumber]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const created = await fetch(`/api/companies/${me.company.id}/vendors`, {
        method: 'POST',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: JSON.stringify({ number: vendorNumber, name: `Quincaillerie ${vendorNumber}` }),
      });
      if (!created.ok) throw new Error(`creating ${vendorNumber} answered ${created.status}`);
      return ((await created.json()) as { id: string }).id;
    },
    [CSRF, number] as const,
  );
}

/** Retires the run's vendor; a vendor is never deleted. */
async function retire(page: Page, id: string): Promise<void> {
  await page.evaluate(
    async ([csrf, vendorId]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const address = `/api/companies/${me.company.id}/vendors/${vendorId}`;
      // The write shape has no id: a body naming one is refused.
      const { id: _, ...fields } = (await (await fetch(address)).json()) as Record<string, unknown>;
      const revised = await fetch(address, {
        method: 'PUT',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: JSON.stringify({ ...fields, isActive: false }),
      });
      if (!revised.ok) throw new Error(`retiring ${vendorId} answered ${revised.status}`);
    },
    [CSRF, id] as const,
  );
}

test('an order is started from its vendor, sent, received in two parts, and its goods are in stock', async ({
  page,
}) => {
  // Two products' worth of set-up, five screens, four accessibility scans and the clean-up.
  test.setTimeout(120_000);
  const run = Date.now().toString(36).toUpperCase();
  const reference = `ACH-${run}`;
  const vendorNumber = `ACH-${run}`;
  await signIn(page);
  await inACompany(page, CSRF);
  const products: string[] = [];
  let vendorId: string | null = null;
  try {
    products.push(await aProduct(page, reference));
    await stockKept(page, products[0], true);
    vendorId = await aVendor(page, vendorNumber);

    await page.goto(`/vendors/${vendorId}`);
    // Drawn in the bar or folded into « ⋮ », by the width the title leaves it: either is there once the page is.
    const offered = page.getByTestId('record-new-purchase');
    await expect(offered.or(page.getByTestId('record-more')).first()).toBeVisible();
    if (!(await offered.isVisible())) {
      await page.getByTestId('record-more').click();
      await page.getByTestId('record-menu-new-purchase').click();
    } else {
      await offered.click();
    }
    await expect(page).toHaveURL(new RegExp(`/purchases/new\\?vendorId=${vendorId}$`));
    await expect(page.getByTestId('field-vendorId')).toHaveValue(
      new RegExp(`Quincaillerie ${vendorNumber}`),
    );

    await page.getByTestId('purchase-line-0-product').fill(reference);
    await page.getByRole('option', { name: `${reference} · Vis ${reference}` }).click();
    await expect(page.getByTestId('purchase-line-0-description')).toHaveValue(`Vis ${reference}`);
    await expect(page.getByTestId('purchase-line-0-unit')).not.toBeEmpty();
    await page.getByTestId('purchase-line-0-quantity').fill('10');
    await page.getByTestId('purchase-line-0-price').fill('0,25');
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('document-action-save').click();
    await expect(toast(page)).toContainText('Le brouillon a été enregistré.');
    await expect(page).toHaveURL(/\/purchases\/[0-9a-f-]{36}$/);
    const orderAddress = page.url();

    await page.getByTestId('document-action-send').click();
    await page.getByTestId('confirm-run').click();
    await expect(toast(page)).toContainText(/La commande .+ est envoyée\./);
    await expect(page.getByTestId('purchase-order-status')).toContainText('Envoyée');
    await expect(page.getByTestId('purchase-order-lines-read')).toBeVisible();
    expect(await wcagViolations(page)).toEqual([]);

    // The first delivery brings four of the ten.
    await page.getByTestId('document-action-receive').click();
    // Focus moves in once the dialog has opened, to its first field: typing before then types into the page behind.
    await expect(page.getByTestId('receive-day')).toBeFocused();
    await page.getByTestId('receive-row-0-quantity').fill('4');
    await page.getByTestId('receive-reference').fill(`BL-${run}`);
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('receive-confirm').click();
    await expect(toast(page)).toContainText('le reste est toujours attendu');
    await expect(page.getByTestId('purchase-order-status')).toContainText('Reçue en partie');
    await expect(page.getByTestId('purchase-order-line-0-received')).toHaveText('4');
    await expect(page.getByTestId('purchase-order-line-0-outstanding')).toHaveText('6');
    await expect(page.getByTestId('purchase-order-receipts')).toContainText(`BL-${run}`);

    // The second brings the rest: the dialog opens on what is still expected.
    await page.getByTestId('document-action-receive').click();
    await expect(page.getByTestId('receive-day')).toBeFocused();
    await expect(page.getByTestId('receive-row-0-quantity')).toHaveValue('6');
    await page.getByTestId('receive-confirm').click();
    await expect(toast(page)).toContainText('toute la commande est reçue');
    await expect(page.getByTestId('purchase-order-status')).toContainText('Reçue');
    await expect(page.getByTestId('document-action-receive')).toHaveCount(0);
    expect(await wcagViolations(page)).toEqual([]);

    await page.goto('/stock');
    await page.getByTestId('list-filter').fill(reference);
    const row = page.locator(`[data-testid^="stock-${reference}-"]`);
    await expect(row).toHaveCount(1);
    await expect(row.locator('[data-column="quantity"]')).toHaveText('10');

    // The list finds the order by its vendor and shows it received.
    await page.goto(`/purchases?q=${encodeURIComponent(vendorNumber)}`);
    const listed = page.locator('[data-testid^="purchase-order-"]').filter({
      hasText: `Quincaillerie ${vendorNumber}`,
    });
    await expect(listed).toHaveCount(1);
    await expect(listed).toContainText('Reçue');
    await listed.getByRole('link').first().click();
    await expect(page).toHaveURL(orderAddress);
  } finally {
    if (products.length > 0) await stockKept(page, products[0], false);
    await forget(page, products);
    if (vendorId !== null) await retire(page, vendorId);
  }
});
