// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test, type Page, type Response } from '@playwright/test';
import { aProduct, aVendor, forget, removed } from './catalogue';
import { runDigits } from './run';
import { inACompany, signIn } from './session';
import { toast } from './toast';

// Who a product is bought from, named on its own page from the vendor book, read again as saved, and taken off.
const CSRF = '0123456789abcdef0123456789abcdef';

test('a product names who it is bought from, found in the vendor book, and keeps exactly those', async ({
  page,
}) => {
  test.setTimeout(60_000);
  const run = runDigits();
  const reference = `VND-${run}`;
  const number = `F${run}`;
  await signIn(page);
  await inACompany(page, CSRF);
  const ids: string[] = [];
  let vendorId: string | null = null;
  try {
    vendorId = await aVendor(page, number);
    ids.push(await aProduct(page, reference));
    await page.goto(`/products/${ids[0]}`);
    await expect(page.getByTestId('field-vendorIds-held')).toHaveCount(0);

    await page.getByTestId('field-vendorIds').fill(number);
    await page.getByRole('option', { name: `${number} · Quincaillerie ${number}` }).click();
    await expect(page.getByTestId(`field-vendorIds-held-${vendorId}`)).toHaveText(
      `${number} · Quincaillerie ${number}`,
    );
    const saved = productSaved(page, ids[0]);
    await page.getByTestId('record-save').click();
    expect((await saved).ok()).toBe(true);
    await expect(toast(page)).toContainText('Le produit a été enregistré.');

    // Read again, the page names it as the product says it.
    await page.reload();
    await expect(page.getByTestId(`field-vendorIds-held-${vendorId}`)).toHaveText(
      `${number} · Quincaillerie ${number}`,
    );

    await page.getByTestId(`field-vendorIds-remove-${vendorId}`).click();
    const cleared = productSaved(page, ids[0]);
    await page.getByTestId('record-save').click();
    expect((await cleared).ok()).toBe(true);
    await page.reload();
    await expect(page.getByTestId('field-vendorIds-held')).toHaveCount(0);
  } finally {
    await forget(page, ids);
    // Nothing names it once the product let it go, so it is deleted rather than left in the shared company.
    if (vendorId !== null) await removed(page, 'vendor', vendorId);
  }
});

/** The PUT of the product's own form, so a step reads the record only once that save has answered. */
function productSaved(page: Page, id: string): Promise<Response> {
  return page.waitForResponse(
    (response) =>
      response.request().method() === 'PUT' &&
      new URL(response.url()).pathname.endsWith(`/products/${id}`),
  );
}
