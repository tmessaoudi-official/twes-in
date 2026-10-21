// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test, type Page } from '@playwright/test';
import { runDigits } from './run';
import { inACompany, signIn } from './session';
import { toast } from './toast';

// The categories a shop files its goods under come from a file of their own, offered on the categories page and first
// in the products page's imports, before the products that name them.
const CSRF = '0123456789abcdef0123456789abcdef';

test('a file of categories, a subcategory under one named above, is offered where categories are and deleted together', async ({
  page,
}) => {
  test.setTimeout(60_000);
  // A leading digit sorts the run's categories first in the shared company's list.
  const parent = `0Cat-${runDigits()}`;
  const child = `0Sub-${runDigits()}`;
  await signIn(page);
  await inACompany(page, CSRF);
  try {
    await page.goto('/products');
    await page.getByTestId('products-import').click();
    await expect(page.getByTestId('products-import-categories')).toHaveAttribute(
      'href',
      '/imports/product-categories',
    );
    await page.keyboard.press('Escape');

    await page.goto('/products/categories');
    await page.getByTestId('product-categories-import').click();
    await expect(page).toHaveURL(/\/imports\/product-categories$/);
    await expect(page.getByTestId('import-title')).toHaveText(
      'Importer des catégories de produits',
    );
    await page.getByTestId('import-file').setInputFiles({
      name: 'categories.csv',
      mimeType: 'text/csv',
      buffer: Buffer.from(`name,parent\n${parent},\n${child},${parent}\n`, 'utf8'),
    });
    await page.getByTestId('import-preview').click();
    await expect(page.getByTestId('import-created')).toContainText('2');
    await page.getByTestId('import-store').click();
    await expect(toast(page)).toContainText('2');

    await page.goto('/products/categories');
    await expect(page.getByTestId(`product-category-${parent}`)).toBeVisible();
    await expect(page.getByTestId(`product-category-${child}`)).toContainText(parent);

    // The category goes with the subcategory chosen beside it: the subcategory no longer holds it.
    expect(await removeTogether(page, [parent, child])).toBe(201);
    await page.reload();
    await expect(page.getByTestId(`product-category-${parent}`)).toHaveCount(0);
  } finally {
    await removeTogether(page, [child, parent]);
  }
});

/** Deletes the run's categories still there in one delete, and answers its status (0 when none was left). */
async function removeTogether(page: Page, names: string[]): Promise<number> {
  return page.evaluate(
    async ([csrf, wanted]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}`;
      const listed = await fetch(`${base}/product-categories`);
      if (!listed.ok) throw new Error(`listing the categories answered ${listed.status}`);
      const ids = ((await listed.json()) as { id: string; name: string }[])
        .filter((row) => wanted.includes(row.name))
        .map((row) => row.id);
      if (ids.length === 0) return 0;
      const deleted = await fetch(`${base}/record-removals`, {
        method: 'POST',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: JSON.stringify({ subject: 'product_category', ids }),
      });
      if (!deleted.ok) throw new Error(`deleting ${wanted.join(', ')} answered ${deleted.status}`);
      return deleted.status;
    },
    [CSRF, names] as const,
  );
}
