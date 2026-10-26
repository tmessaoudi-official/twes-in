// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { inACompany, signIn } from './session';
import { toast } from './toast';
import { wcagViolations } from './axe';
import { rowMenuItem, rowMore } from './rows';

// Price lists through the real stack: the owner opens the screen, files a list for everyone, finds it in the list,
// deletes it asking nothing, takes it back from the toast and deletes it again. One database is shared by the whole suite, so the name is unique to
// the run and the list is deleted by its name whatever happens. The prices of a list need products, which the seeded
// company of CI does not hold: their rows are covered by the unit specs and the API's functional tests.
const CSRF = '0123456789abcdef0123456789abcdef';

async function forget(page: Page, name: string): Promise<void> {
  await page.evaluate(
    async ([csrf, listName]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}/price-lists`;
      const lists = (await (await fetch(base)).json()) as { id: string; name: string }[];
      const ids = lists.filter((row) => row.name === listName).map((row) => row.id);
      if (ids.length === 0) return;
      const deleted = await fetch(`/api/companies/${me.company.id}/record-removals`, {
        method: 'POST',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: JSON.stringify({ subject: 'price_list', ids }),
      });
      if (!deleted.ok) throw new Error(`the price list ${listName} stayed: ${deleted.status}`);
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

    // Deleted asking nothing: the toast takes it back, « Récemment supprimés » for a day.
    await rowMore(page, row).click();
    await rowMenuItem(page, 'delete').click();
    await expect(page.getByTestId('confirm-run')).toHaveCount(0);
    await expect(toast(page)).toContainText(`« ${name} » est supprimé.`);
    await expect(page.getByTestId(row)).toHaveCount(0);
    await toast(page).getByTestId('toast-action').click();
    await expect(page.getByTestId(row)).toContainText('Tous les clients');

    await rowMore(page, row).click();
    await rowMenuItem(page, 'delete').click();
    await expect(page.getByTestId(row)).toHaveCount(0);
  } finally {
    await forget(page, name);
  }
});

// The editor is hand-written, not a descriptor form: a refusal the API alone makes is still said under its field, and
// the form stays open with what was typed. Nothing is saved, so nothing is put back.
test('a name longer than the API keeps is refused under the name, and the editor keeps what was typed', async ({
  page,
}) => {
  await signIn(page);
  await inACompany(page, CSRF);
  const name = `Promo ${Date.now()} `.padEnd(121, 'x');
  await page.goto('/price-lists');
  await page.getByTestId('price-list-add').click();
  await page.getByTestId('price-list-name').fill(name);
  await page.getByTestId('price-list-save').click();

  await expect(page.getByTestId('price-list-name-error')).toContainText(
    'Texte refusé\u00a0: 120 caractères au plus.',
  );
  await expect(page.getByTestId('price-list-name')).toHaveValue(name);
});
