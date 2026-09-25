// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { wcagViolations } from './axe';
import { aProduct, forget, stockKept } from './catalogue';
import { inACompany, signIn } from './session';

// docs/SPEC.md § 7, 2026-09-24 12:10: « À surveiller », the conditions true now, and its count on the home.
const CSRF = '0123456789abcdef0123456789abcdef';

/** Sets the product's reorder point in every establishment, or clears it (clearing a cleared one is no error). */
async function reorderPoint(page: Page, productId: string, quantity: string | null): Promise<void> {
  await page.evaluate(
    async ([csrf, id, point]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}/products/${id}/reorder-points`;
      const rows = (await (
        await fetch(base, { headers: { Accept: 'application/json' } })
      ).json()) as { establishmentId: string }[];
      for (const row of rows) {
        const answered = await fetch(`${base}/${row.establishmentId}`, {
          method: point === null ? 'DELETE' : 'PUT',
          headers: { 'content-type': 'application/json', 'csrf-token': csrf },
          body: point === null ? undefined : JSON.stringify({ quantity: point }),
        });
        if (!answered.ok) throw new Error(`the reorder point answered ${answered.status}`);
      }
    },
    [CSRF, productId, quantity] as const,
  );
}

test('a product at its reorder point shows on « À surveiller », counted on the home, and leaves once cleared', async ({
  page,
}) => {
  const reference = `WATCH-${Date.now().toString(36).toUpperCase()}`;
  await signIn(page);
  await inACompany(page, CSRF);
  const ids: string[] = [];
  try {
    ids.push(await aProduct(page, reference));
    await stockKept(page, ids[0], true);
    await reorderPoint(page, ids[0], '1');

    await page.goto('/');
    await expect(page.getByTestId('home-watch')).toContainText('À surveiller :');
    await page.getByTestId('home-watch').getByRole('link').click();

    await expect(page).toHaveURL(/\/watch$/);
    const item = page
      .getByTestId('watch-list')
      .getByRole('listitem')
      .filter({ hasText: reference });
    await expect(item).toContainText('seuil de réapprovisionnement 1');
    expect(await wcagViolations(page)).toEqual([]);

    await item.getByRole('link').click();
    await expect(page).toHaveURL(new RegExp(`/products/${ids[0]}$`));

    await reorderPoint(page, ids[0], null);
    await page.goto('/watch');
    await expect(page.getByTestId('watch-page')).toBeVisible();
    await expect(
      page.getByTestId('watch-page').getByRole('listitem').filter({ hasText: reference }),
    ).toHaveCount(0);
  } finally {
    if (ids[0] !== undefined) await reorderPoint(page, ids[0], null);
    await forget(page, ids);
  }
});
