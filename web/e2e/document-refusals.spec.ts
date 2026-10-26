// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { aCustomer, aProduct, aVendor, forget, removed, retireVendor } from './catalogue';
import { inACompany, signIn } from './session';

// What the API refuses on a document's save is said under the field it names, in its own words, on every document's
// editor: a line's under the line. The product is retired between choosing it and saving, which the form cannot see,
// so only the API refuses it. Nothing is saved: the customer is deleted and the product and the vendor retired at the end.
const CSRF = '0123456789abcdef0123456789abcdef';

/** Picks the run's product on the first line, typed rather than scrolled to. */
async function pickProduct(page: Page, testId: string, reference: string): Promise<void> {
  await page.getByTestId(testId).fill(reference);
  await page.getByRole('option', { name: new RegExp(`^${reference} · `) }).click();
}

const named = { invoices: 'an invoice', quotes: 'a quote', 'delivery-notes': 'a delivery note' };

for (const kind of ['invoices', 'quotes', 'delivery-notes'] as const) {
  test(`${named[kind]}'s line whose product was retired since it was chosen is refused under its product`, async ({
    page,
  }) => {
    const run = Date.now().toString(36).toUpperCase();
    const reference = `REF-${run}`;
    const customerNumber = `E2E-REF-${run}`;
    await signIn(page);
    await inACompany(page, CSRF);
    const customer = await aCustomer(page, customerNumber);
    const product = await aProduct(page, reference);
    try {
      await page.goto(`/${kind}/new`);
      const party = { invoices: 'invoice', quotes: 'quote', 'delivery-notes': 'delivery-note' }[
        kind
      ];
      await page.getByTestId(`${party}-customer`).fill(customerNumber);
      await page.getByRole('option', { name: new RegExp(`^${customerNumber} · `) }).click();
      await pickProduct(page, 'line-0-product', reference);
      await page.getByTestId('line-0-quantity').fill('1');

      if (kind === 'invoices') {
        // The document's own refusal lands under its own field, in the words of its reason.
        await page.getByTestId('field-discountAmount').fill('50');
        await page.getByTestId('document-action-save').click();
        await expect(page.getByTestId('field-error-discountAmount')).toContainText(
          'La remise du document dépasse ce que font ses lignes',
        );
        await page.getByTestId('field-discountAmount').fill('');
      }

      await forget(page, [product]);
      await page.getByTestId('document-action-save').click();

      await expect(page.getByTestId('line-0-product-error')).toHaveText(
        `Le produit « ${reference} » est désactivé : choisissez-en un autre, ou réactivez-le dans Produits.`,
      );
      await expect(page).toHaveURL(new RegExp(`/${kind}/new$`));
    } finally {
      await forget(page, [product]).catch(() => undefined);
      await removed(page, 'customer', customer);
    }
  });
}

test('an order line whose product was retired since it was chosen is refused under its product', async ({
  page,
}) => {
  const run = Date.now().toString(36).toUpperCase();
  const reference = `REF-${run}`;
  const vendorNumber = `REF-${run}`;
  await signIn(page);
  await inACompany(page, CSRF);
  const product = await aProduct(page, reference);
  const vendor = await aVendor(page, vendorNumber);
  try {
    await page.goto(`/purchases/new?vendorId=${vendor}`);
    await expect(page.getByTestId('field-vendorId')).toHaveValue(
      new RegExp(`Quincaillerie ${vendorNumber}`),
    );
    await pickProduct(page, 'purchase-line-0-product', reference);
    await page.getByTestId('purchase-line-0-quantity').fill('1');
    await forget(page, [product]);
    await page.getByTestId('document-action-save').click();

    await expect(page.getByTestId('purchase-line-0-product-error')).toHaveText(
      `Le produit « ${reference} » est désactivé : choisissez-en un autre, ou réactivez-le dans Produits.`,
    );
    // Said under the field, it is not said again on the line as a whole.
    await expect(page.getByTestId('purchase-line-0-refused')).toHaveCount(0);
  } finally {
    await forget(page, [product]).catch(() => undefined);
    await retireVendor(page, vendor);
  }
});

// A step asked in a dialog keeps the dialog open when refused, and says why under the field it names.
test('a quote answered on a day after today keeps the answer asked, saying why under the day', async ({
  page,
}) => {
  const run = Date.now().toString(36).toUpperCase();
  const customerNumber = `E2E-ANS-${run}`;
  await signIn(page);
  await inACompany(page, CSRF);
  await aCustomer(page, customerNumber);
  await page.goto('/quotes/new');
  await page.getByTestId('quote-customer').fill(customerNumber);
  await page.getByRole('option', { name: new RegExp(`^${customerNumber} · `) }).click();
  await page.getByTestId('line-0-description').fill(`Tournage ${run}`);
  await page.getByTestId('line-0-quantity').fill('1');
  await page.getByTestId('line-0-price').fill('100');
  await page.getByTestId('document-action-save').click();
  await expect(page).toHaveURL(/\/quotes\/[0-9a-f-]{36}$/);
  await page.getByTestId('document-action-send').click();
  await page.getByTestId('confirm-run').click();
  await expect(page.getByTestId('quote-status')).toContainText(/Envoyé|Sent/);

  await page.getByTestId('document-action-accept').click();
  await expect(page.getByTestId('quote-answered-on')).toBeFocused();
  // Two days ahead is after today on any company's clock.
  const ahead = new Date(Date.now() + 2 * 86_400_000).toISOString().slice(0, 10);
  const [year, month, day] = ahead.split('-');
  await page.getByTestId('quote-answered-on').fill(`${day}/${month}/${year}`);
  await page.getByTestId('quote-answer-confirm').click();

  await expect(page.getByTestId('quote-answered-on-error')).toHaveText(
    'Date refusée : aujourd’hui au plus tard.',
  );
  await expect(page.getByTestId('quote-answer-title')).toBeVisible();
  await expect(page.getByTestId('quote-error')).toHaveCount(0);
  // Put right, the same dialog records the answer.
  await page.getByTestId('quote-answered-on').fill('');
  await page.getByTestId('quote-answer-confirm').click();
  await expect(page.getByTestId('quote-status')).toContainText(/Accepté|Accepted/);
});
