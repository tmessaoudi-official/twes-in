// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { wcagViolations } from './axe';
import { aProduct, aVendor, forget, retireVendor, stockKept } from './catalogue';
import { runDigits } from './run';
import { inACompany, signIn } from './session';
import { toast } from './toast';

// « Reçu par » on a goods receipt through the real stack: the person receiving names who of the shop took the goods in
// (a member offered by name, the person recording first, or a name typed), the order shows it on its receipt, it is put
// right in the dialog every document shares, and the orders list finds the order by that name. The order stays (a
// received order is a record); the product and the vendor are retired at the end.
const CSRF = '0123456789abcdef0123456789abcdef';

test('a receipt names who received the goods, shows it, puts it right and is found by it', async ({
  page,
}) => {
  test.setTimeout(120_000);
  const run = runDigits();
  const reference = `RCP-${run}`;
  const vendorNumber = `RCP-${run}`;
  const typed = `Chauffeur ${run}`;
  const corrected = `Livreur ${run}`;
  await signIn(page);
  await inACompany(page, CSRF);
  const products: string[] = [];
  let vendorId: string | null = null;
  try {
    products.push(await aProduct(page, reference));
    await stockKept(page, products[0], true);
    vendorId = await aVendor(page, vendorNumber);
    const orderId = await aSentOrder(page, vendorId, products[0]);
    await page.goto(`/purchases/${orderId}`);

    // The first part is received by a member: the field opens empty, offers the members with the person recording
    // first, and copies the one picked.
    await page.getByTestId('document-action-receive').click();
    await expect(page.getByTestId('receive-day')).toBeFocused();
    await page.getByTestId('receive-row-0-quantity').fill('4');
    const name = page.getByTestId('receive-receiver-name');
    await expect(name).toHaveValue('');
    await name.click();
    const offered = page.getByTestId('receive-receiver-contact');
    await expect(offered.first()).toBeVisible();
    const member = (await offered.first().innerText()).trim();
    await offered.first().click();
    await expect(name).toHaveValue(member);
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('receive-confirm').click();
    await expect(toast(page)).toContainText('le reste est toujours attendu');
    const receipts = page.getByTestId('purchase-order-receipts');
    await expect(receipts).toContainText(`Reçu par ${member}`);

    // The rest is received by someone who is no member: a name typed.
    await page.getByTestId('document-action-receive').click();
    await expect(page.getByTestId('receive-day')).toBeFocused();
    await page.getByTestId('receive-receiver-name').fill(typed);
    await page.getByTestId('receive-confirm').click();
    await expect(toast(page)).toContainText('toute la commande est reçue');
    await expect(receipts).toContainText(`Reçu par ${typed}`);

    // Put right: the newest receipt is listed first.
    await receipts.locator('[data-testid$="-correct-receiver"]').first().click();
    await expect(page.getByTestId('receiver-dialog-name')).toHaveValue(typed);
    await page.getByTestId('receiver-dialog-name').fill(corrected);
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('receiver-dialog-save').click();
    await expect(toast(page)).toContainText('Qui a reçu la marchandise est corrigé.');
    await expect(receipts).toContainText(`Reçu par ${corrected}`);
    await expect(receipts).not.toContainText(typed);

    // The list finds the order by who received, and says it.
    await page.goto(`/purchases?q=${encodeURIComponent(corrected)}`);
    const listed = page.locator(`[data-testid="purchase-order-${orderId}"]`);
    await expect(listed).toHaveCount(1);
    // Every order the search keeps, in either layout; the « Nouvelle commande » link shares the prefix.
    await expect(
      page.locator('[data-testid^="purchase-order-"]:not([data-testid="purchase-order-add"])'),
    ).toHaveCount(1);
  } finally {
    if (products.length > 0) await stockKept(page, products[0], false);
    await forget(page, products);
    if (vendorId !== null) await retireVendor(page, vendorId);
  }
});

/** A draft of ten of the product, sent through the API as the order screen sends it; its id. */
async function aSentOrder(page: Page, vendorId: string, productId: string): Promise<string> {
  return page.evaluate(
    async ([csrf, vendor, product]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const orders = `/api/companies/${me.company.id}/purchase-orders`;
      const headers = { 'content-type': 'application/json', 'csrf-token': csrf };
      const created = await fetch(orders, {
        method: 'POST',
        headers: { ...headers, 'idempotency-key': crypto.randomUUID() },
        body: JSON.stringify({
          vendorId: vendor,
          lines: [{ productId: product, quantity: '10' }],
        }),
      });
      if (!created.ok) throw new Error(`drafting the order answered ${created.status}`);
      const { id } = (await created.json()) as { id: string };
      const sent = await fetch(`${orders}/${id}/send`, { method: 'POST', headers });
      if (!sent.ok) throw new Error(`sending the order answered ${sent.status}`);
      return id;
    },
    [CSRF, vendorId, productId] as const,
  );
}
