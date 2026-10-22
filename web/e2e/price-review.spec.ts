// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { aProduct, forget, receivedAt, removed, stockKept } from './catalogue';
import { inACompany, signIn } from './session';
import { toast } from './toast';
import { runDigits } from './run';

// docs/SPEC.md § 7, cost and price: a receipt that moves a product's cost under a kept price says the margin it moved,
// offers the price that keeps the margin, and every open screen shows it at once, with no reload.
const CSRF = '0123456789abcdef0123456789abcdef';

test('a receipt that moves the margin offers the price that keeps it, and the product page shows it live', async ({
  page,
}) => {
  // Two receipts, two settlements and the clean-up.
  test.setTimeout(60_000);
  const run = runDigits();
  const reference = `PRV-${run}`;
  await signIn(page);
  await inACompany(page, CSRF);
  const ids: string[] = [];
  try {
    // Sold at 1, first bought at 0.5: a first cost moves no margin, so nothing is said yet.
    ids.push(await aProduct(page, reference));
    await stockKept(page, ids[0], true);
    await receivedAt(page, ids[0], '0.5');

    await page.goto('/stock');
    await page.getByTestId('stock-receive').click();
    await page.getByTestId('field-productId').fill(reference);
    await page.getByRole('option', { name: `${reference} · Vis ${reference}` }).click();
    await page.getByTestId('field-quantity').fill('1');
    await page.getByTestId('field-unitCost').fill('0,6');
    await page.getByTestId('field-applyCost').click();
    await page.getByRole('option', { name: 'Prendre le coût de cette réception' }).click();
    await page.getByTestId('stock-movement-save').click();

    // The receipt is recorded, and the margin it moved is said at once, with the price that keeps it a click away.
    await expect(toast(page)).toContainText(
      `La marge de Vis ${reference} est passée de 50,0 % à 40,0 %.`,
    );
    await toast(page).getByRole('button', { name: 'Ajuster le prix' }).click();
    await expect(toast(page)).toContainText(`Prix de Vis ${reference} ajusté à`);

    await page.goto(`/products/${ids[0]}`);
    await expect(page.getByTestId('field-unitPriceNet')).toHaveValue(/^1[,.]2/);
    await expect(page.getByTestId('product-price-review')).toHaveCount(0);

    // Another receipt while the page is open: the notice comes in without a reload, and settling it goes as it came.
    await receivedAt(page, ids[0], '0.9');
    await expect(page.getByTestId('product-price-review-margin')).toHaveText(
      'La marge est passée de 50,0 % à 25,0 %.',
    );
    await page.getByTestId('product-price-review-keep').click();
    await expect(toast(page)).toContainText(`Prix de Vis ${reference} gardé à`);
    await expect(page.getByTestId('product-price-review')).toHaveCount(0);
    await expect(page.getByTestId('field-unitPriceNet')).toHaveValue(/^1[,.]2/);
  } finally {
    await forget(page, ids);
  }
});

test('a price that followed its cost is put back from the toast that says it', async ({ page }) => {
  // Its own category follows the cost, so the shared company's choice stays as the other scenarios read it.
  test.setTimeout(60_000);
  const run = runDigits();
  const reference = `PRF-${run}`;
  await signIn(page);
  await inACompany(page, CSRF);
  const ids: string[] = [];
  let categoryId: string | null = null;
  try {
    categoryId = await followingCategory(page, `Suit son coût ${run}`);
    ids.push(await aProduct(page, reference));
    await filedUnder(page, ids[0], categoryId);
    await stockKept(page, ids[0], true);
    await receivedAt(page, ids[0], '0.5');

    await page.goto('/stock');
    await page.getByTestId('stock-receive').click();
    await page.getByTestId('field-productId').fill(reference);
    await page.getByRole('option', { name: `${reference} · Vis ${reference}` }).click();
    await page.getByTestId('field-quantity').fill('1');
    await page.getByTestId('field-unitCost').fill('0,6');
    await page.getByTestId('field-applyCost').click();
    await page.getByRole('option', { name: 'Prendre le coût de cette réception' }).click();
    await page.getByTestId('stock-movement-save').click();

    // The price moved with its cost, said with the way back: « Annuler » puts back the price it replaced.
    await expect(toast(page)).toContainText(`Le prix de Vis ${reference} est passé de`);
    await toast(page).getByRole('button', { name: 'Annuler' }).click();
    await expect(toast(page)).toContainText(`Prix de Vis ${reference} remis à`);

    await page.goto(`/products/${ids[0]}`);
    await expect(page.getByTestId('field-unitPriceNet')).toHaveValue(/^1([,.]0+)?$/);
    await expect(page.getByTestId('field-costPrice')).toHaveValue(/^0[,.]60*$/);
  } finally {
    if (ids[0] !== undefined) await filedUnder(page, ids[0], null);
    await forget(page, ids);
    if (categoryId !== null) await removed(page, 'product_category', categoryId);
  }
});

/** A category of its own whose products' prices follow their cost; its id. */
async function followingCategory(page: Page, name: string): Promise<string> {
  return page.evaluate(
    async ([csrf, categoryName]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}`;
      const created = await fetch(`${base}/product-categories`, {
        method: 'POST',
        headers: {
          'content-type': 'application/json',
          'csrf-token': csrf,
          'idempotency-key': crypto.randomUUID(),
        },
        body: JSON.stringify({ name: categoryName, parentId: null }),
      });
      if (!created.ok) throw new Error(`creating the category answered ${created.status}`);
      const { id } = (await created.json()) as { id: string };
      const set = await fetch(`${base}/settings/article.price_on_cost_change`, {
        method: 'PUT',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: JSON.stringify({ level: 'product_category', value: 'follow', productCategoryId: id }),
      });
      if (!set.ok) throw new Error(`the category's price setting answered ${set.status}`);
      return id;
    },
    [CSRF, name] as const,
  );
}

/** Files the product under the category, or under none. */
async function filedUnder(page: Page, productId: string, categoryId: string | null): Promise<void> {
  await page.evaluate(
    async ([csrf, id, category]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}`;
      const {
        id: _,
        barcodes: __,
        ...fields
      } = (await (await fetch(`${base}/products/${id}`)).json()) as Record<string, unknown>;
      const saved = await fetch(`${base}/products/${id}`, {
        method: 'PUT',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: JSON.stringify({ ...fields, categoryId: category }),
      });
      if (!saved.ok) throw new Error(`filing ${id} answered ${saved.status}`);
    },
    [CSRF, productId, categoryId] as const,
  );
}
