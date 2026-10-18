// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test, type Page } from '@playwright/test';
import { aCustomer, aProduct, aVendor, forget, retireVendor } from './catalogue';
import { inACompany, signIn } from './session';

// « Ajouter à… »: products chosen on the list go onto an open draft, which its editor shows on its lines, unsaved.
const CSRF = '0123456789abcdef0123456789abcdef';

/** A draft invoice for the customer, with no line yet; its id. */
async function aDraftInvoice(page: Page, customerId: string): Promise<string> {
  return page.evaluate(
    async ([csrf, customer]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const created = await fetch(`/api/companies/${me.company.id}/invoices`, {
        method: 'POST',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: JSON.stringify({
          customerId: customer,
          lines: [],
        }),
      });
      if (!created.ok) throw new Error(`creating the draft answered ${created.status}`);
      return ((await created.json()) as { id: string }).id;
    },
    [CSRF, customerId] as const,
  );
}

/** Cancels the draft, so it is no longer offered as an open one. */
async function cancelled(page: Page, invoiceId: string): Promise<void> {
  await page.evaluate(
    async ([csrf, id]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const done = await fetch(`/api/companies/${me.company.id}/invoices/${id}/cancel`, {
        method: 'POST',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: '{}',
      });
      if (!done.ok) throw new Error(`cancelling the draft answered ${done.status}`);
    },
    [CSRF, invoiceId] as const,
  );
}

test('products chosen on the list go onto an open draft invoice, unsaved until it is saved', async ({
  page,
}) => {
  const run = Date.now().toString(36).toUpperCase();
  const reference = `ADD-${run}`;
  const customer = `E2E-ADD-${run}`;
  await signIn(page);
  await inACompany(page, CSRF);
  const products: string[] = [];
  let draft: string | null = null;
  try {
    products.push(await aProduct(page, `${reference}-A`));
    products.push(await aProduct(page, `${reference}-B`));
    draft = await aDraftInvoice(page, await aCustomer(page, customer));

    await page.goto('/products');
    await page.getByTestId('list-filter').fill(reference);
    for (const id of products) {
      await page.getByTestId(`list-select-row-${id}`).locator('input').check();
    }
    await page.getByTestId('products-bulk-add-to').click();

    // The kinds offered start with the invoice; picking the customer offers that customer's open drafts.
    const picker = page.getByRole('dialog');
    await expect(picker.getByTestId('add-to-title')).toContainText('2');
    await picker.getByTestId('add-to-customer').fill(customer);
    await page.getByRole('option', { name: new RegExp(`^${customer} · `) }).click();
    await expect(picker.getByTestId('add-to-list').getByRole('button')).toHaveCount(1);
    await picker.getByTestId(`add-to-draft-${draft}`).click();

    await expect(page).toHaveURL(new RegExp(`/invoices/${draft}$`));
    await expect(page.getByTestId('line-0-description')).toHaveValue(`Vis ${reference}-A`);
    await expect(page.getByTestId('line-1-description')).toHaveValue(`Vis ${reference}-B`);
    await expect(page.getByTestId('line-2')).toHaveCount(0);

    // The address forgot them (the URL above carries no query), and nothing was saved: the draft still has no line.
    const saved = await page.evaluate(async (id) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const read = await fetch(`/api/companies/${me.company.id}/invoices/${id}`);
      return ((await read.json()) as { lines: unknown[] }).lines.length;
    }, draft);
    expect(saved).toBe(0);
  } finally {
    if (draft !== null) await cancelled(page, draft);
    await forget(page, products);
  }
});

/** A draft order to the vendor naming the product once; its id. */
async function aDraftOrder(page: Page, vendorId: string, productId: string): Promise<string> {
  return page.evaluate(
    async ([csrf, vendor, product]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const created = await fetch(`/api/companies/${me.company.id}/purchase-orders`, {
        method: 'POST',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: JSON.stringify({
          vendorId: vendor,
          lines: [{ productId: product, quantity: '10', unitPriceNet: '0.5' }],
        }),
      });
      if (!created.ok) throw new Error(`creating the order answered ${created.status}`);
      return ((await created.json()) as { id: string }).id;
    },
    [CSRF, vendorId, productId] as const,
  );
}

/** Cancels the draft order, so it is no longer offered as an open one. */
async function orderCancelled(page: Page, orderId: string): Promise<void> {
  await page.evaluate(
    async ([csrf, id]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const done = await fetch(`/api/companies/${me.company.id}/purchase-orders/${id}/cancel`, {
        method: 'POST',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: '{}',
      });
      if (!done.ok) throw new Error(`cancelling the order answered ${done.status}`);
    },
    [CSRF, orderId] as const,
  );
}

test('products chosen on the list go onto an open order to a vendor, found by that vendor', async ({
  page,
}) => {
  const run = Date.now().toString(36).toUpperCase();
  const reference = `ADDPO-${run}`;
  const vendorNumber = `E2E-ADDPO-${run}`;
  await signIn(page);
  await inACompany(page, CSRF);
  const products: string[] = [];
  let vendorId: string | null = null;
  let order: string | null = null;
  try {
    products.push(await aProduct(page, `${reference}-A`));
    products.push(await aProduct(page, `${reference}-B`));
    vendorId = await aVendor(page, vendorNumber);
    order = await aDraftOrder(page, vendorId, products[0]);

    await page.goto('/products');
    await page.getByTestId('list-filter').fill(reference);
    for (const id of products) {
      await page.getByTestId(`list-select-row-${id}`).locator('input').check();
    }
    await page.getByTestId('products-bulk-add-to').click();

    // An order is found by its vendor: the field asks for one, and offers that vendor's open drafts.
    const picker = page.getByRole('dialog');
    await picker.getByTestId('add-to-kind-purchase_order').click();
    await picker.getByTestId('add-to-vendor').fill(vendorNumber);
    await page.getByRole('option', { name: new RegExp(`^${vendorNumber} · `) }).click();
    await expect(picker.getByTestId('add-to-list').getByRole('button')).toHaveCount(1);
    await picker.getByTestId(`add-to-draft-${order}`).click();

    // The product already on the order counts one more; the other starts its own line, named after it.
    await expect(page).toHaveURL(new RegExp(`/purchases/${order}$`));
    await expect(page.getByTestId('purchase-line-0-quantity')).toHaveValue('11');
    await expect(page.getByTestId('purchase-line-1-description')).toHaveValue(`Vis ${reference}-B`);
    await expect(page.getByTestId('purchase-line-1-quantity')).toHaveValue('1');
    await expect(page.getByTestId('purchase-line-2')).toHaveCount(0);

    // Nothing was saved: the draft still has its one line.
    const saved = await page.evaluate(async (id) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const read = await fetch(`/api/companies/${me.company.id}/purchase-orders/${id}`);
      return ((await read.json()) as { lines: unknown[] }).lines.length;
    }, order);
    expect(saved).toBe(1);
  } finally {
    if (order !== null) await orderCancelled(page, order);
    await forget(page, products);
    if (vendorId !== null) await retireVendor(page, vendorId);
  }
});
