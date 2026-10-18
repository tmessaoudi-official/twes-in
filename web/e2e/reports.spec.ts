// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { wcagViolations } from './axe';
import { choose } from './select';
import { inACompany, OPERATOR_PASSWORD, signIn } from './session';

// « Rapports » through the real stack (docs/SPEC.md § 7, 2026-09-24 12:05): an expense recorded under this run's own
// category is counted in « Dépenses par catégorie » for this month, its row opens the expenses list narrowed to it, and
// the report exports under its own name. The database is shared, so the run's category is retired at the end; the
// recorded expense stays, as a recorded expense does.
const CSRF = '0123456789abcdef0123456789abcdef';

/** The run's category, by its name: its id is what the report's row and the list's filter carry. */
async function categoryId(page: Page, name: string): Promise<string> {
  return page.evaluate(async (categoryName) => {
    const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
    const response = await fetch(`/api/companies/${me.company.id}/expense-categories`);
    if (!response.ok) throw new Error(`reading the categories answered ${response.status}`);
    const rows = (await response.json()) as { id: string; name: string }[];
    const found = rows.find((row) => row.name === categoryName);
    if (!found) throw new Error(`no category named ${categoryName}`);
    return found.id;
  }, name);
}

/** Deactivates the run's category. */
async function retire(page: Page, name: string): Promise<void> {
  await page.evaluate(
    async ([csrf, categoryName]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}`;
      const categories = (await (await fetch(`${base}/expense-categories`)).json()) as {
        id: string;
        name: string;
        parentId: string | null;
        version: number;
      }[];
      const category = categories.find((row) => row.name === categoryName);
      if (category) {
        const revised = await fetch(`${base}/expense-categories/${category.id}`, {
          method: 'PUT',
          headers: { 'content-type': 'application/json', 'csrf-token': csrf },
          body: JSON.stringify({
            name: category.name,
            parentId: category.parentId,
            isActive: false,
            version: category.version,
          }),
        });
        if (!revised.ok) throw new Error(`retiring ${categoryName} answered ${revised.status}`);
      }
    },
    [CSRF, name] as const,
  );
}

test('a recorded expense is counted by category, opens its list, and the report exports', async ({
  page,
}) => {
  // An expense filed and recorded, the report read twice, a list and a download: more than the default 30 s.
  test.setTimeout(90_000);
  const run = Date.now().toString(36).toUpperCase();
  const category = `Rapport ${run}`;
  const description = `Fournitures ${run}`;
  await signIn(page);
  await inACompany(page, CSRF);
  try {
    await page.goto('/expenses/categories');
    await page.getByTestId('expense-category-add').click();
    await page.getByTestId('field-name').fill(category);
    await page.getByTestId('expense-category-save').click();
    await expect(page.getByTestId(`expense-category-${category}`)).toBeVisible();
    const id = await categoryId(page, category);

    // Dated today by default, so inside « Ce mois-ci », the period a report opens on.
    await page.goto('/expenses/new');
    await page.getByTestId('field-description').fill(description);
    await page.getByTestId('field-categoryId').click();
    await page.getByRole('option', { name: category }).click();
    await page.getByTestId('bill-line-0-gross').fill('119');
    await choose(page, 'bill-line-0-taxes', /19\s%\)?$/);
    await page.getByTestId('record-save').click();
    await expect(page).toHaveURL(/\/expenses\/[0-9a-f-]{36}$/);
    await page.getByTestId('expense-record').click();
    await expect(page.getByTestId('expense-fixed')).toBeVisible();

    await page.goto('/reports');
    const card = page.getByTestId('report-expenses-by-category');
    await expect(card).toContainText('Dépenses par catégorie');
    await card.click();
    await expect(page).toHaveURL(/\/reports\/expenses-by-category\?/);
    await expect(page.getByTestId('report-title')).toContainText('Dépenses par catégorie');

    // This month against the month before, by default: both read back in the address.
    const opened = new URL(page.url());
    const from = opened.searchParams.get('from') ?? '';
    const to = opened.searchParams.get('to') ?? '';
    expect(from).toMatch(/^\d{4}-\d{2}-01$/);
    expect(to >= from).toBe(true);
    expect(opened.searchParams.get('compare')).toBe('previous');

    const drill = page.getByTestId(`report-drill-${id}`);
    await expect(drill).toHaveText(category);
    const row = page.getByTestId('report-table').getByRole('row').filter({ has: drill });
    await expect(row).toContainText('119');
    expect(await wcagViolations(page)).toEqual([]);

    // With nothing to compare, the period alone is read, and the row is still there.
    await page.getByTestId('report-compare').click();
    await page.getByTestId('report-compare-none').click();
    await expect.poll(() => new URL(page.url()).searchParams.get('compare')).toBe('none');
    await expect(row).toContainText('119');

    const download = page.waitForEvent('download');
    await page.getByTestId('report-export').click();
    await page.getByTestId('report-export-csv').click();
    const stepUp = page.getByTestId('step-up-password');
    const asked = await Promise.race([
      download.then(() => false),
      stepUp.waitFor({ state: 'visible' }).then(
        () => true,
        () => false,
      ),
    ]);
    if (asked) {
      await stepUp.fill(OPERATOR_PASSWORD);
      await page.getByTestId('step-up-confirm').click();
    }
    expect((await download).suggestedFilename()).toBe(`expenses-by-category_${from}_${to}.csv`);

    // The row opens the expenses list narrowed to what it counted: this category, recorded or paid, this period.
    await drill.click();
    await expect(page).toHaveURL(/\/expenses\?/);
    const list = new URL(page.url()).searchParams;
    expect(list.get('category')).toBe(id);
    expect(list.get('status')).toBe('recorded,paid');
    expect(list.get('date.from')).toBe(from);
    expect(list.get('date.to')).toBe(to);
    await expect(page.getByTestId('expenses-table')).toContainText(description);
  } finally {
    await retire(page, category);
  }
});
