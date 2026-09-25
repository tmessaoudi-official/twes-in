// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { inACompany, signIn } from './session';
import { toast } from './toast';
import { wcagViolations } from './axe';
import { rowAction } from './rows';
import { aProduct, forget, gtin } from './catalogue';
import { cardHasFocus, scan as scanned } from './scan';

// G5 products through the real stack: in the seeded Tunisian company, the owner files a category, creates a service
// in it in hours with the 19 % VAT by default, finds its price at the currency's three decimals, revises it, and
// finds it in the list under its category. One database is shared by the whole suite, so the names are unique to
// the run, and the product is deactivated (products are never deleted) and taken out of the category before the
// category is deleted.
const CSRF = '0123456789abcdef0123456789abcdef';

/** Deactivates the run's product and takes it out of its category, then deletes the category. */
async function retire(page: Page, reference: string, categoryName: string): Promise<void> {
  await page.evaluate(
    async ([csrf, productReference, category]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}`;
      // The list is paged: the product is found by its reference, then read whole as the single record it is.
      const listed = (await (
        await fetch(`${base}/products?q=${encodeURIComponent(productReference)}`)
      ).json()) as { member: { id: string; reference: string }[] };
      const hit = listed.member.find((row) => row.reference === productReference);
      const product =
        hit === undefined
          ? undefined
          : ((await (await fetch(`${base}/products/${hit.id}`)).json()) as {
              id: string;
              reference: string;
            });
      if (product) {
        // The write shape has no id: a body naming one is refused, and the category could then not be deleted.
        const { id, ...fields } = product;
        const revised = await fetch(`${base}/products/${id}`, {
          method: 'PUT',
          headers: { 'content-type': 'application/json', 'csrf-token': csrf },
          body: JSON.stringify({ ...fields, categoryId: null, isActive: false }),
        });
        if (!revised.ok) throw new Error(`retiring ${productReference} answered ${revised.status}`);
      }
      const categories = (await (await fetch(`${base}/product-categories`)).json()) as {
        id: string;
        name: string;
      }[];
      const found = categories.find((row) => row.name === category);
      if (found) {
        const deleted = await fetch(`${base}/product-categories/${found.id}`, {
          method: 'DELETE',
          headers: { 'csrf-token': csrf },
        });
        if (!deleted.ok) throw new Error(`deleting ${category} answered ${deleted.status}`);
      }
    },
    [CSRF, reference, categoryName] as const,
  );
}

test('a product is filed in a category, priced at the currency scale and revised', async ({
  page,
}) => {
  const run = Date.now().toString(36).toUpperCase();
  const categoryName = `E2E ${run}`;
  const reference = `E2E-${run}`;
  await signIn(page);
  await inACompany(page, CSRF);
  try {
    await page.goto('/products/categories');
    await page.getByTestId('product-category-add').click();
    await page.getByTestId('field-name').fill(categoryName);
    await page.getByTestId('product-category-save').click();
    await expect(page.getByTestId(`product-category-${categoryName}`)).toBeVisible();
    expect(await wcagViolations(page)).toEqual([]);

    // The category says its products are sold by the hour; the product filed in it hears so.
    await rowAction(page, `product-category-${categoryName}`, 'edit').click();
    await page.getByTestId('field-article__default_unit').fill('HUR');
    await page.getByTestId('article-defaults-save').click();
    await expect(toast(page)).toContainText('Les valeurs par défaut ont été enregistrées.');
    expect(await wcagViolations(page)).toEqual([]);

    await page.goto('/products/new');
    await page.getByTestId('field-reference').fill(reference);
    await page.getByTestId('field-name').fill(`Audit fiscal ${run}`);
    await page.getByTestId('field-kind').click();
    await page.getByRole('option', { name: 'Service' }).click();
    await page.getByTestId('field-categoryId').click();
    await page.getByRole('option', { name: categoryName }).click();
    await page.getByTestId('field-unitId').click();
    await page.getByRole('option', { name: /^HUR · / }).click();
    // docs/SPEC.md § 7, 2026-09-19 21:55: a decimal comma is a price, whatever the interface language.
    await page.getByTestId('field-unitPriceNet').fill('120,5');
    await page.getByRole('checkbox', { name: /19/ }).check();
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('record-save').click();

    await expect(page).toHaveURL(/\/products\/[0-9a-f-]{36}$/);
    await expect(page.getByTestId('product-title')).toContainText(reference);
    await expect(page.getByTestId('field-unitPriceNet')).toHaveValue(/^120[,.]500$/);
    // docs/SPEC.md § 8 row 23 (review C8): the saved product's own screen, whose id only this scenario holds.
    expect(await wcagViolations(page)).toEqual([]);

    await page.getByTestId('field-unitPriceNet').fill('135');
    // The creation's toast may still be showing, so the revision is known saved by its own answer, not by a toast.
    const revised = page.waitForResponse(
      (response) =>
        response.request().method() === 'PUT' &&
        /\/products\/[0-9a-f-]{36}$/.test(new URL(response.url()).pathname),
    );
    await page.getByTestId('record-save').click();
    expect((await revised).ok()).toBe(true);
    await expect(toast(page)).toContainText('Le produit a été enregistré.');

    // The record splits into tabs (design review finding 4): what the category set is one click away.
    await page.getByRole('tab', { name: 'Valeurs par défaut' }).click();
    await expect(page.getByTestId('field-article__default_unit')).toHaveValue('HUR');

    await page.goto('/products');
    // Filtered: the shared company holds more products than a page, and the API searches the words.
    await page.getByTestId('list-filter').fill(reference);
    await expect(page).toHaveURL(new RegExp(`[?&]q=${reference}`));
    await expect(page.getByTestId(`product-${reference}`)).toContainText(categoryName);
    await expect(page.getByTestId(`product-${reference}`)).toContainText('135,000');
    await expect(page.getByTestId(`product-${reference}`)).toContainText('HUR');
  } finally {
    await retire(page, reference, categoryName);
  }
});

// docs/SPEC.md § 7, 2026-09-22 11:05 and 22:26: a product answers to several codes, scanned in one after another;
// a code is one product's only, and a code spelled whole finds its product.
test('a product is given its codes by scanning them, and a code finds it', async ({ page }) => {
  const run = Date.now().toString(36).toUpperCase();
  const unit = gtin(`200${String(Date.now() % 1_000_000_000).padStart(9, '0')}`);
  const pack = gtin(`1${unit.slice(0, 12)}`);
  await signIn(page);
  await inACompany(page, CSRF);
  const ids: string[] = [];
  try {
    ids.push(await aProduct(page, `SCAN-${run}`));
    ids.push(await aProduct(page, `SCAN2-${run}`));

    await page.goto(`/products/${ids[0]}`);
    await page.getByRole('tab', { name: 'Codes-barres' }).click();
    const scan = page.getByTestId('product-barcode-scan');
    // What a handheld scanner does: the code and Enter, typed into the focused field.
    await scan.fill(unit);
    await scan.press('Enter');
    await scan.fill(pack);
    await scan.press('Enter');
    await expect(scan).toHaveValue('');
    await expect(page.getByTestId('product-barcode-code-0')).toHaveValue(unit);
    await expect(page.getByTestId('product-barcode-code-1')).toHaveValue(pack);

    // The carton enters twelve at once: it is a pack.
    await page.getByTestId('product-barcode-role-1').click();
    await page.getByRole('option', { name: 'Colis' }).click();
    // A pack starts at two; typing before that render lands let it write into the field mid-fill ("212").
    await expect(page.getByTestId('product-barcode-quantity-1')).toHaveValue('2');
    await page.getByTestId('product-barcode-quantity-1').fill('12');
    // The same code scanned again is pointed at, not listed twice.
    await scan.fill(`0${unit}`);
    await scan.press('Enter');
    await expect(page.getByTestId('product-barcodes-duplicate')).toContainText('ligne 1');
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('product-barcodes-save').click();
    await expect(toast(page)).toContainText('Codes enregistrés.');

    await page.reload();
    await page.getByRole('tab', { name: 'Codes-barres' }).click();
    await expect(page.getByTestId('product-barcode-code-1')).toHaveValue(pack);
    await expect(page.getByTestId('product-barcode-quantity-1')).toHaveValue('12');

    // Another product cannot take the carton's code: the refusal names who holds it, under the row.
    await page.goto(`/products/${ids[1]}`);
    await page.getByRole('tab', { name: 'Codes-barres' }).click();
    await page.getByTestId('product-barcode-scan').fill(pack);
    await page.getByTestId('product-barcode-scan').press('Enter');
    await page.getByTestId('product-barcodes-save').click();
    await expect(page.getByTestId('product-barcode-problem-0')).toContainText(`SCAN-${run}`);
    expect(await wcagViolations(page)).toEqual([]);

    // Spelled whole in the list's search, a code finds its product.
    await page.goto('/products');
    await page.getByTestId('list-filter').fill(pack);
    await expect(page.getByTestId(`product-SCAN-${run}`)).toBeVisible();
    await expect(page.getByTestId(`product-SCAN2-${run}`)).toHaveCount(0);

    // Scanned with no field focused, a GS1 carton label opens what it names; one key goes on from there.
    await page.goto('/products');
    // The shell reads a scan once the person is signed in and the page is up, as a person would scan.
    await expect(page.getByTestId('list-filter')).toBeVisible();
    await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur());
    await scanned(page, `]C101${pack}10LOT-${run}`);
    const card = page.getByTestId('product-scan-card');
    await expect(card).toContainText(`SCAN-${run}`);
    await expect(page.getByTestId('product-scan-enters')).toContainText('Colis');
    await expect(page.getByTestId('product-scan-enters')).toContainText('12');
    await expect(page.getByTestId('product-scan-lot')).toContainText(`LOT-${run}`);
    expect(await wcagViolations(page)).toEqual([]);
    await cardHasFocus(page);
    await page.keyboard.press('c');
    await expect(page).toHaveURL(new RegExp(`/products/${ids[0]}\\?tab=codes$`));
    await expect(page.getByRole('tab', { name: 'Codes-barres' })).toHaveAttribute(
      'aria-selected',
      'true',
    );
    await expect(page.getByTestId('product-barcode-code-1')).toHaveValue(pack);

    // From the card, one key starts an invoice with the product scanned: the carton enters its twelve on the first line.
    await page.goto('/products');
    await expect(page.getByTestId('list-filter')).toBeVisible();
    await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur());
    await scanned(page, pack);
    await expect(page.getByTestId('product-scan-action-invoice')).toBeVisible();
    await cardHasFocus(page);
    await page.keyboard.press('i');
    await expect(page).toHaveURL(/\/invoices\/new$/);
    await expect(page.getByTestId('line-0-description')).toHaveValue(`Vis SCAN-${run}`);
    await expect(page.getByTestId('line-0-quantity')).toHaveValue('12');
    await expect(page.getByTestId('line-1')).toHaveCount(0);

    // A code nobody holds: Enter creates the product it names, which then lists the code, waiting to be saved.
    const fresh = `NEW-${run}`;
    await page.goto('/products');
    await expect(page.getByTestId('list-filter')).toBeVisible();
    await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur());
    await scanned(page, fresh);
    await expect(page.getByTestId('product-scan-none')).toBeVisible();
    await cardHasFocus(page);
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(new RegExp(`/products/new\\?barcode=${fresh}$`));
    await page.getByTestId('field-reference').fill(fresh);
    await page.getByTestId('field-name').fill(`Vis ${fresh}`);
    await page.getByTestId('field-unitPriceNet').fill('1');
    await page.getByTestId('record-save').click();
    await expect(page).toHaveURL(new RegExp(`/products/[^/?]+\\?tab=codes&add=${fresh}$`));
    ids.push(new URL(page.url()).pathname.split('/').at(-1)!);
    await expect(page.getByTestId('product-barcode-code-0')).toHaveValue(fresh);
    await page.getByTestId('product-barcodes-save').click();
    await expect(toast(page)).toContainText('Codes enregistrés.');

    // Scanned into an invoice line, the carton puts its product on the line and enters the twelve it holds; scanned
    // over, a line takes the other product, as a person correcting a wrong scan does.
    await page.goto('/invoices/new');
    const line = page.getByTestId('line-0-product');
    await line.click();
    await scanned(page, pack);
    await expect(page.getByTestId('line-0-description')).toHaveValue(`Vis SCAN-${run}`);
    await expect(page.getByTestId('line-0-quantity')).toHaveValue('12');
    await line.click();
    await line.press('ControlOrMeta+a');
    await scanned(page, `SCAN2-${run}`);
    await expect(page.getByTestId('line-0-description')).toHaveValue(`Vis SCAN2-${run}`);

    // With no field focused the draft counts scans as a till does (docs/SPEC.md § 7, 2026-09-23 09:30): another
    // product starts a line, the same one adds to it, "3×" typed before the carton makes it three cartons, and
    // Ctrl Z takes the last scan back.
    await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur());
    await scanned(page, unit);
    await expect(page.getByTestId('line-1-description')).toHaveValue(`Vis SCAN-${run}`);
    await expect(page.getByTestId('line-1-quantity')).toHaveValue('1');
    await scanned(page, unit);
    await expect(page.getByTestId('line-1-quantity')).toHaveValue('2');
    await expect(page.getByTestId('line-2')).toHaveCount(0);
    // Typed by hand, far slower than a scanner, so the shell reads it as a count rather than a code.
    await page.keyboard.type('3*', { delay: 120 });
    await expect(page.getByTestId('scan-count')).toContainText('3');
    await scanned(page, pack);
    await expect(page.getByTestId('line-1-quantity')).toHaveValue('38');
    await expect(page.getByTestId('scan-count')).toHaveCount(0);
    await page.keyboard.press('ControlOrMeta+z');
    await expect(page.getByTestId('line-1-quantity')).toHaveValue('2');
  } finally {
    await forget(page, ids);
  }
});
