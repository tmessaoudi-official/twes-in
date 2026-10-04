// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { wcagViolations } from './axe';
import { aProduct, forget } from './catalogue';
import { inACompany, signIn } from './session';

// A product keeps several homes in an establishment, in an order, the first the main one: two places are added, the
// second is moved above the first and becomes the main one, and a place taken away leaves the other. The run's own
// product and shelf are unique, and both are put away afterwards.
const CSRF = '0123456789abcdef0123456789abcdef';

/** Takes the product's homes in the establishment away, then deletes the shelf the run made. */
async function putAway(page: Page, productId: string, shelfCode: string): Promise<void> {
  await page.evaluate(
    async ([csrf, id, code]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}`;
      const homes = (await (await fetch(`${base}/products/${id}/home-locations`)).json()) as {
        establishmentId: string;
      }[];
      for (const establishmentId of new Set(homes.map((home) => home.establishmentId))) {
        const cleared = await fetch(`${base}/products/${id}/home-locations/${establishmentId}`, {
          method: 'DELETE',
          headers: { 'csrf-token': csrf },
        });
        if (!cleared.ok) throw new Error(`clearing the homes answered ${cleared.status}`);
      }
      const locations = (await (await fetch(`${base}/stock-locations`)).json()) as {
        id: string;
        code: string;
      }[];
      const shelf = locations.find((row) => row.code === code);
      if (shelf) {
        const gone = await fetch(`${base}/stock-locations/${shelf.id}`, {
          method: 'DELETE',
          headers: { 'csrf-token': csrf },
        });
        if (!gone.ok) throw new Error(`deleting ${code} answered ${gone.status}`);
      }
    },
    [CSRF, productId, shelfCode] as const,
  );
}

test('a product keeps several homes in an establishment, the first the main one', async ({
  page,
}) => {
  const run = Date.now().toString(36).toUpperCase();
  const shelfCode = `E2E-${run}`;
  await signIn(page);
  await inACompany(page, CSRF);
  const productId = await aProduct(page, `HOM-${run}`);
  try {
    await page.goto('/stock/locations');
    await expect(page.getByTestId('stock-location-add')).toBeVisible();
    await page.getByTestId('stock-location-add').click();
    await page.getByTestId('field-establishmentId').click();
    await page.getByRole('option').first().click();
    await page.getByTestId('field-code').fill(shelfCode);
    await page.getByTestId('field-name').fill('Étagère e2e');
    await page.getByTestId('stock-location-save').click();
    await expect(page.getByTestId(`stock-location-${shelfCode}`)).toBeVisible();

    await page.goto(`/products/${productId}`);
    await page.getByRole('tab', { name: 'Où il est rangé' }).click();
    await expect(page.getByTestId('product-homes-none')).toBeVisible();

    const add = async (name: RegExp): Promise<void> => {
      await page.getByTestId('product-home-location').click();
      await page.getByRole('option', { name }).click();
      await page.getByTestId('product-home-add').click();
    };
    const rows = page.getByTestId('product-homes-list').getByRole('listitem');

    await add(/^[^›]+$/);
    await expect(rows).toHaveCount(1);
    await expect(rows.first()).toContainText('Principal');

    await add(new RegExp(`${shelfCode}`));
    await expect(rows).toHaveCount(2);
    await expect(rows.nth(1)).toContainText(shelfCode);
    await expect(rows.nth(1)).not.toContainText('Principal');
    expect(await wcagViolations(page)).toEqual([]);

    await rows.nth(1).getByRole('button', { name: 'Monter' }).click();
    await expect(rows.first()).toContainText(shelfCode);
    await expect(rows.first()).toContainText('Principal');
    await expect(rows.nth(1)).not.toContainText('Principal');

    await rows.first().getByRole('button', { name: 'Retirer cet emplacement habituel' }).click();
    await expect(rows).toHaveCount(1);
    await expect(rows.first()).toContainText('Principal');
    await expect(rows.first()).not.toContainText(shelfCode);
  } finally {
    await putAway(page, productId, shelfCode);
    await forget(page, [productId]);
  }
});
