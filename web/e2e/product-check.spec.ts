// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test, type Page, type Response } from '@playwright/test';
import { aProduct, forget } from './catalogue';
import { runDigits } from './run';
import { choose } from './select';
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

// C88: a company leaves out the reasons saying a field is empty, chosen in Paramètres; a product lacking only those is
// then said to lack nothing, the list offers them no more, and the reset brings them back.
test('the reasons a company leaves out in Paramètres are said nowhere, until the setting is reset', async ({
  page,
}) => {
  test.setTimeout(90_000);
  const reference = `0LEFT-${runDigits()}`;
  await signIn(page);
  await inACompany(page, CSRF);
  await forgetLeftOut(page);
  const ids: string[] = [];
  try {
    // Created with a price and nothing else, it lacks a vendor, its VAT, a cost, a barcode and a category.
    ids.push(await aProduct(page, reference));
    await page.goto(`/products/${ids[0]}`);
    await expect(page.getByTestId('product-check-reasons')).toContainText('Code-barres manquant');
    await expect(page.getByTestId('product-check-reasons')).toContainText('Catégorie manquante');

    await page.goto('/settings');
    const field = page.getByTestId(`field-${LEFT_OUT_FIELD}`);
    await expect(field).toBeVisible();
    // Nothing is left out yet, and the page opens with nothing to save: an unset list is not a change.
    await expect(page.getByTestId('record-save')).toBeDisabled();
    for (const reason of LACKING) {
      await choose(page, `field-${LEFT_OUT_FIELD}`, reason);
    }
    await page.getByTestId('record-save').click();
    await expect(toast(page)).toContainText('Les paramètres ont été enregistrés.');
    await page.reload();
    // A closed select names its first choices and counts the rest (« +2 »): each is read as ticked in the open list.
    await field.click();
    for (const reason of LACKING) {
      await expect(page.getByRole('option', { name: reason })).toHaveAttribute(
        'aria-selected',
        'true',
      );
    }
    await page.keyboard.press('Escape');
    await expect(page.getByTestId('record-save')).toBeDisabled();

    // Lacking only what is left out, the product lacks nothing that is said, and is not to check.
    await page.goto(`/products/${ids[0]}`);
    await expect(page.getByTestId('product-title')).toContainText(reference);
    await expect(page.getByTestId('product-check-reasons')).toHaveCount(0);
    await page.goto(`/products?q=${reference}&toCheck=yes`);
    await expect(page.getByTestId(`product-${reference}`)).toHaveCount(0);
    await page.goto(`/products?q=${reference}&toCheck=no`);
    await expect(page.getByTestId(`product-${reference}`)).toBeVisible();
    // The list's « À vérifier » filter offers only the reasons still said.
    const offered = await offeredReasons(page);
    expect(offered).not.toContain('barcode_missing');
    expect(offered).not.toContain('category_missing');
    expect(offered).toContain('price_missing');

    // Reset, every reason is said again.
    await page.goto('/settings');
    await page.getByTestId(`settings-reset-${LEFT_OUT}`).click();
    await expect(page.getByTestId(`settings-override-${LEFT_OUT}`)).toHaveCount(0);
    await page.goto(`/products/${ids[0]}`);
    await expect(page.getByTestId('product-check-reasons')).toContainText('Code-barres manquant');
    expect(await offeredReasons(page)).toContain('barcode_missing');
  } finally {
    await forgetLeftOut(page);
    await forget(page, ids);
  }
});

const LEFT_OUT = 'article.check_left_out';
/** What a product made by `aProduct` lacks, as the settings page and the product name it: all but its price. */
const LACKING = [
  'Fournisseur manquant',
  'TVA manquante',
  'Prix de revient manquant',
  'Code-barres manquant',
  'Catégorie manquante',
];
const LEFT_OUT_FIELD = LEFT_OUT.replaceAll('.', '__');

/** The company's own list of reasons left out forgotten, so the shared company says every reason again. */
async function forgetLeftOut(page: Page): Promise<void> {
  const status = await page.evaluate(
    async ([csrf, key]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const response = await fetch(
        `/api/companies/${me.company.id}/settings/${encodeURIComponent(key)}?level=company`,
        { method: 'DELETE', headers: { 'csrf-token': csrf } },
      );
      return response.status;
    },
    [CSRF, LEFT_OUT],
  );
  expect([204, 404]).toContain(status);
}

/** The reasons the products list offers to filter on, as the API answers the list's options. */
async function offeredReasons(page: Page): Promise<string[]> {
  return page.evaluate(async () => {
    const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
    const options = (await (
      await fetch(`/api/companies/${me.company.id}/product-options`)
    ).json()) as { checkReasons?: string[] };
    return options.checkReasons ?? [];
  });
}

/** The PUT of the product's own form, so a step reads the record only once that save has answered. */
function productSaved(page: Page, id: string): Promise<Response> {
  return page.waitForResponse(
    (response) =>
      response.request().method() === 'PUT' &&
      new URL(response.url()).pathname.endsWith(`/products/${id}`),
  );
}
