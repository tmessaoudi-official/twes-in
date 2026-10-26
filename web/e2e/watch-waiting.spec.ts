// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { wcagViolations } from './axe';
import { aProduct, aVendor, forget, retireVendor, stockKept } from './catalogue';
import { runDigits } from './run';
import { inACompany, signIn } from './session';

// « À surveiller » tells whoever receives what the shop buys that an order's goods are due: the person who sent it and
// the one at the door are often two. The order is listed once its day has come, leads to its page, and the subject
// leads to the orders list showing the open ones. The order is cancelled at the end (a sent order is kept, numbered);
// the product and the vendor are retired. A draft delivery note waiting for a validator needs hours to pass, which a
// browser scenario cannot wait: the API's own test (WaitingDocumentsWatchTest) holds it.
const CSRF = '0123456789abcdef0123456789abcdef';

test('an order whose goods are due is on « À surveiller » for whoever receives, and leads to it', async ({
  page,
}) => {
  test.setTimeout(90_000);
  const run = runDigits();
  const reference = `DUE-${run}`;
  const vendorNumber = `DUE-${run}`;
  await signIn(page);
  await inACompany(page, CSRF);
  const products: string[] = [];
  let vendorId: string | null = null;
  let orderId: string | null = null;
  try {
    products.push(await aProduct(page, reference));
    await stockKept(page, products[0], true);
    vendorId = await aVendor(page, vendorNumber);
    // Two days back in UTC is past in every company's own day.
    const due = new Date(Date.now() - 2 * 86_400_000).toISOString().slice(0, 10);
    orderId = await aSentOrder(page, vendorId, products[0], due);

    await page.goto('/watch/purchase_order.to_receive');
    const row = page
      .getByTestId('watch-table')
      .getByRole('row')
      .filter({ hasText: `Quincaillerie ${vendorNumber}` });
    await expect(row).toHaveCount(1);
    await expect(row.locator('[data-column="late_days"]')).not.toHaveText('');
    expect(await wcagViolations(page)).toEqual([]);

    await expect(page.getByTestId('watch-elsewhere')).toHaveAttribute(
      'href',
      '/purchases?status=sent,partly_received',
    );
    await row.getByRole('link').first().click();
    await expect(page).toHaveURL(new RegExp(`/purchases/${orderId}$`));
  } finally {
    if (orderId !== null) await cancelOrder(page, orderId);
    if (products.length > 0) await stockKept(page, products[0], false);
    await forget(page, products);
    if (vendorId !== null) await retireVendor(page, vendorId);
  }
});

/** A draft of ten of the product expected on that day, sent through the API as the order screen sends it; its id. */
async function aSentOrder(
  page: Page,
  vendorId: string,
  productId: string,
  expectedOn: string,
): Promise<string> {
  return page.evaluate(
    async ([csrf, vendor, product, day]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const orders = `/api/companies/${me.company.id}/purchase-orders`;
      const headers = { 'content-type': 'application/json', 'csrf-token': csrf };
      const created = await fetch(orders, {
        method: 'POST',
        headers: { ...headers, 'idempotency-key': crypto.randomUUID() },
        body: JSON.stringify({
          vendorId: vendor,
          expectedOn: day,
          lines: [{ productId: product, quantity: '10' }],
        }),
      });
      if (!created.ok) throw new Error(`drafting the order answered ${created.status}`);
      const { id } = (await created.json()) as { id: string };
      const sent = await fetch(`${orders}/${id}/send`, { method: 'POST', headers });
      if (!sent.ok) throw new Error(`sending the order answered ${sent.status}`);
      return id;
    },
    [CSRF, vendorId, productId, expectedOn] as const,
  );
}

/** Withdraws the run's order, nothing of which arrived, so it waits for nobody. */
async function cancelOrder(page: Page, id: string): Promise<void> {
  await page.evaluate(
    async ([csrf, orderId]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const cancelled = await fetch(
        `/api/companies/${me.company.id}/purchase-orders/${orderId}/cancel`,
        { method: 'POST', headers: { 'content-type': 'application/json', 'csrf-token': csrf } },
      );
      if (!cancelled.ok) throw new Error(`cancelling the order answered ${cancelled.status}`);
    },
    [CSRF, id] as const,
  );
}
