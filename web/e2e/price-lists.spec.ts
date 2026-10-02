// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { inACompany, signIn } from './session';
import { toast } from './toast';
import { wcagViolations } from './axe';
import { rowMenuItem, rowMore } from './rows';

// Price lists through the real stack: the owner opens the screen, files a list for everyone, finds it in the list,
// and deletes it again behind the confirmation. One database is shared by the whole suite, so the name is unique to
// the run and the list is deleted by its name whatever happens. The prices of a list need products, which the seeded
// company of CI does not hold: their rows are covered by the unit specs and the API's functional tests.
const CSRF = '0123456789abcdef0123456789abcdef';

async function forget(page: Page, name: string): Promise<void> {
  await page.evaluate(
    async ([csrf, listName]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}/price-lists`;
      const lists = (await (await fetch(base)).json()) as { id: string; name: string }[];
      for (const list of lists.filter((row) => row.name === listName)) {
        await fetch(`${base}/${list.id}`, { method: 'DELETE', headers: { 'csrf-token': csrf } });
      }
    },
    [CSRF, name] as const,
  );
}

test('the owner files a price list, finds it, and deletes it', async ({ page }) => {
  await signIn(page);
  await inACompany(page, CSRF);
  const name = `Promo ${Date.now()}`;
  try {
    await page.goto('/price-lists');
    await expect(page.getByTestId('price-lists-title')).toBeVisible();

    await page.getByTestId('price-list-add').click();
    await page.getByTestId('price-list-name').fill(name);
    await page.getByTestId('price-list-save').click();
    await expect(toast(page)).toBeVisible();

    const row = `price-list-${name}`;
    await expect(page.getByTestId(row)).toContainText('Tous les clients');
    await expect(page.getByTestId(row)).toContainText('Toujours');
    expect(await wcagViolations(page)).toEqual([]);

    await rowMore(page, row).click();
    await rowMenuItem(page, 'delete').click();
    await page.getByTestId('confirm-run').click();
    await expect(page.getByTestId(row)).toHaveCount(0);
  } finally {
    await forget(page, name);
  }
});
