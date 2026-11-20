// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test, type Page } from '@playwright/test';
import { runDigits } from './run';
import { inACompany, signIn } from './session';
import { rowMenuItem, rowMore } from './rows';
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

// Where the company takes the links off (records.configuration_links), a category holding a subcategory is offered its
// delete, the toast says what lost its link once the delete's own has closed, and « Annuler » puts the link back.
test('a category holding a subcategory goes where the company takes the links off, and comes back with them', async ({
  page,
}) => {
  test.setTimeout(60_000);
  const parent = `0Cat-${runDigits()}`;
  const child = `0Sub-${runDigits()}`;
  await signIn(page);
  await inACompany(page, CSRF);
  await created(page, parent, null);
  await created(page, child, parent);
  let cleared = false;
  try {
    await page.goto('/products/categories');
    const row = `product-category-${parent}`;
    await expect(page.getByTestId(row)).toBeVisible();
    // Refused as the company keeps it: not offered.
    await expect(rowMore(page, row)).toHaveCount(0);

    await linksCleared(page, true);
    cleared = true;
    await page.reload();
    await rowMore(page, row).click();
    await rowMenuItem(page, 'delete').click();
    await expect(toast(page)).toContainText(`« ${parent} » est supprimé`);
    await expect(page.getByTestId(`product-category-${child}`)).not.toContainText(parent);
    await toast(page).getByTestId('toast-close').click();
    await expect(toast(page)).toHaveText(
      /Le lien vers la fiche supprimée a été retiré de 1 sous-catégorie/,
    );
    await toast(page).getByTestId('toast-close').click();

    await page.goto('/company/recently-deleted');
    const removal = page.locator('li[data-testid^="removal-"]', { hasText: parent });
    await expect(removal).toHaveCount(1);
    await removal.getByRole('button').click();
    await expect(toast(page)).toContainText('remis');
    await page.goto('/products/categories');
    await expect(page.getByTestId(`product-category-${child}`)).toContainText(parent);
  } finally {
    if (cleared) await linksCleared(page, false);
    await removeTogether(page, [child, parent]);
  }
});

/** Files a category through the API, under the named one when given. */
async function created(page: Page, name: string, parent: string | null): Promise<void> {
  await page.evaluate(
    async ([csrf, wanted, above]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}/product-categories`;
      let parentId: string | null = null;
      if (above !== null) {
        const listed = (await (await fetch(base)).json()) as { id: string; name: string }[];
        parentId = listed.find((row) => row.name === above)?.id ?? null;
        if (parentId === null) throw new Error(`no category ${above}`);
      }
      const answer = await fetch(base, {
        method: 'POST',
        headers: {
          'content-type': 'application/json',
          'csrf-token': csrf,
          'idempotency-key': crypto.randomUUID(),
        },
        body: JSON.stringify({ name: wanted, parentId }),
      });
      if (!answer.ok) throw new Error(`filing ${wanted} answered ${answer.status}`);
    },
    [CSRF, name, parent] as const,
  );
}

/** Has the company's deletes take configuration links off, or puts its default back. */
async function linksCleared(page: Page, cleared: boolean): Promise<void> {
  await page.evaluate(
    async ([csrf, on]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}/settings/records.configuration_links`;
      const answer = on
        ? await fetch(base, {
            method: 'PUT',
            headers: { 'content-type': 'application/json', 'csrf-token': csrf },
            body: JSON.stringify({ level: 'company', value: 'clear' }),
          })
        : await fetch(`${base}?level=company`, {
            method: 'DELETE',
            headers: { 'csrf-token': csrf },
          });
      if (!answer.ok) throw new Error(`the links setting answered ${answer.status}`);
    },
    [CSRF, cleared] as const,
  );
}

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
