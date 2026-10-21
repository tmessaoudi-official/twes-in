// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test, type Page, type Response } from '@playwright/test';
import { aProduct, forget } from './catalogue';
import { runDigits } from './run';
import { inACompany, signIn } from './session';
import { toast } from './toast';

// docs/SPEC.md § 7, 2026-10-20 01:40: « à vérifier » by hand with a note, beside what a product lacks; a filter on the
// list, a subject in « À surveiller », and the mark taken off as it was put.
const CSRF = '0123456789abcdef0123456789abcdef';

test('a product marked « À vérifier » with its note is named on its page, in the list filter and in « À surveiller »', async ({
  page,
}) => {
  test.setTimeout(60_000);
  // A leading digit sorts the product first in « À surveiller », which lists by reference, ahead of the shared company's.
  const reference = `0CHK-${runDigits()}`;
  await signIn(page);
  await inACompany(page, CSRF);
  const ids: string[] = [];
  try {
    ids.push(await aProduct(page, reference));
    await page.goto(`/products/${ids[0]}`);
    // Created bare: it lacks a barcode and a category, which the page names before anything is marked.
    await expect(page.getByTestId('product-check-reasons')).toContainText('Code-barres manquant');
    await expect(page.getByTestId('product-check-marked')).toHaveCount(0);

    await page.getByTestId('field-toCheck').locator('input').check();
    await page.getByTestId('field-checkNote').fill('Prix à confirmer avec le fournisseur');
    const saved = productSaved(page, ids[0]);
    await page.getByTestId('record-save').click();
    expect((await saved).ok()).toBe(true);
    await expect(toast(page)).toContainText('Le produit a été enregistré.');
    await expect(page.getByTestId('product-check-marked')).toContainText('Marqué à la main');
    await expect(page.getByTestId('product-check-note')).toHaveText(
      'Prix à confirmer avec le fournisseur',
    );

    // The list narrowed to what is to check finds it; narrowed to nothing to check, it does not.
    await page.goto(`/products?q=${reference}&toCheck=yes`);
    await expect(page.getByTestId(`product-${reference}`)).toBeVisible();
    await page.goto(`/products?q=${reference}&toCheck=no`);
    await expect(page.getByTestId(`product-${reference}`)).toHaveCount(0);

    // « À surveiller » lists it with what it lacks, its mark said first, and its note.
    await page.goto('/watch/product.to_check');
    const row = page.getByTestId('watch-table').getByRole('row').filter({ hasText: reference });
    // What it lacks never gives way for width; the note does when other rows' reasons run long, and the product's
    // page shows it whole.
    await expect(row).toContainText('Marqué à la main');
    await expect(row).toContainText('Code-barres manquant');

    // Taken off, the mark and its note go together; what it lacks stays named.
    await page.goto(`/products/${ids[0]}`);
    await page.getByTestId('field-toCheck').locator('input').uncheck();
    await expect(page.getByTestId('field-checkNote')).toHaveCount(0);
    const unmarked = productSaved(page, ids[0]);
    await page.getByTestId('record-save').click();
    expect((await unmarked).ok()).toBe(true);
    await expect(page.getByTestId('product-check-marked')).toHaveCount(0);
    await expect(page.getByTestId('product-check-reasons')).toContainText('Code-barres manquant');
  } finally {
    await forget(page, ids);
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
