// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Locator, type Page, test } from '@playwright/test';
import { wcagViolations } from './axe';
import { inACompany, signIn } from './session';
import { toast } from './toast';
import { runDigits } from './run';

// Row 179 through the real stack: an expense is made recurring from its bar, on the last Friday of each month and of
// variable amount; the toast leads to « Récurrentes », a tab of Dépenses, which says how often in words; it is revised
// to every two months, paused and deleted there. The database is shared by the whole suite: the draft made for the
// run is deleted at the end, and the recurring expense by the scenario itself (or by the cleanup when it failed).
const CSRF = '0123456789abcdef0123456789abcdef';

/** A draft expense of one untaxed line, paid to someone named in words, made for the run. */
async function aDraftExpense(page: Page, description: string): Promise<string> {
  return page.evaluate(
    async ([csrf, text]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const response = await fetch(`/api/companies/${me.company.id}/expenses`, {
        method: 'POST',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: JSON.stringify({
          date: new Date().toISOString().slice(0, 10),
          description: text,
          payee: 'Topnet',
          lines: [{ amountNet: '89', taxComponentIds: [] }],
        }),
      });
      if (!response.ok) throw new Error(`POST /expenses answered ${response.status}`);
      return ((await response.json()) as { id: string }).id;
    },
    [CSRF, description] as const,
  );
}

async function tidy(page: Page, expenseId: string): Promise<void> {
  const statuses = await page.evaluate(
    async ([csrf, id]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}`;
      // Whatever a failed run left behind: a recurring expense of this model goes first.
      const recurring = (await (await fetch(`${base}/recurring-expenses`)).json()) as {
        id: string;
        modelExpenseId: string;
      }[];
      const left: number[] = [];
      const ids = recurring.filter((one) => one.modelExpenseId === id).map((one) => one.id);
      if (ids.length > 0) {
        const deleted = await fetch(`${base}/record-removals`, {
          method: 'POST',
          headers: { 'content-type': 'application/json', 'csrf-token': csrf },
          body: JSON.stringify({ subject: 'recurring_expense', ids }),
        });
        left.push(deleted.status);
      }
      const gone = await fetch(`${base}/expenses/${id}`, {
        method: 'DELETE',
        headers: { 'csrf-token': csrf },
      });
      return [...left, gone.status];
    },
    [CSRF, expenseId] as const,
  );
  expect(statuses.every((status) => status >= 200 && status < 300)).toBe(true);
}

/** Has a single Select take an option: it closes by itself, where an Escape would close the dialog it sits in. */
async function select(page: Page, testId: string, name: RegExp | string): Promise<void> {
  await page.getByTestId(testId).click();
  await page.getByRole('option', { name }).click();
  await expect(page.getByRole('listbox')).toHaveCount(0);
}

/** A row's action: a button when the list shows it as one, else from the row's « ⋮ ». */
async function rowAction(page: Page, row: Locator, id: string): Promise<void> {
  const button = row.getByTestId(new RegExp(`^row-action-${id}-`));
  if ((await button.count()) > 0) {
    await button.click();
    return;
  }
  await row.getByTestId(/^row-more-/).click();
  await page.getByTestId(new RegExp(`^row-menu-${id}-`)).click();
}

test('an expense made recurring is listed under « Récurrentes » in words, revised, paused and deleted', async ({
  page,
}) => {
  // Two dialogs, three accessibility scans and a list read again after each step: more than the default 30 s.
  test.setTimeout(60_000);
  // The state gives way first on a laptop, where the schedule and the next date matter more: a wide window shows it.
  await page.setViewportSize({ width: 1600, height: 900 });
  await signIn(page);
  await inACompany(page, CSRF);
  const description = `Internet ${runDigits()}`;
  const expenseId = await aDraftExpense(page, description);
  try {
    await page.goto(`/expenses/${expenseId}`);
    await page.getByTestId('record-make-recurring').click();
    await expect(page.getByTestId('recurring-expense-dialog-title')).toBeVisible();
    // The dialog takes the focus once it has opened: typed before, the keys would reach the page behind.
    await expect(page.locator('mat-dialog-container :focus')).toHaveCount(1);
    // The weekday and its rank are asked only once a month's day is chosen that names one.
    await expect(page.getByTestId('field-weekday')).toHaveCount(0);
    await select(page, 'field-anchor', /dernier vendredi/);
    await select(page, 'field-weekday', 'vendredi');
    await expect(page.getByTestId('field-monthDay')).toHaveCount(0);
    await page.getByTestId('field-variableAmount').locator('input').check();
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('recurring-expense-dialog-confirm').click();

    await expect(toast(page)).toContainText('récurrente');
    await toast(page).getByTestId('toast-action').click();
    await expect(page).toHaveURL(/\/expenses\/recurring$/);
    await expect(page.getByTestId('recurring-expenses-tab')).toBeVisible();

    const row = page
      .getByTestId('recurring-expenses-table')
      .locator('[data-testid^="recurring-expense-"]', { hasText: description });
    await expect(row).toHaveCount(1);
    await expect(row.locator('[data-column="schedule"]')).toContainText(
      'Chaque mois, le dernier vendredi',
    );
    await expect(row.locator('[data-column="amount"]')).toContainText('Montant variable');
    await expect(row.locator('[data-column="state"]')).toContainText('Active');
    expect(await wcagViolations(page)).toEqual([]);

    await rowAction(page, row, 'revise');
    await expect(page.getByTestId('recurring-expense-dialog-title')).toBeVisible();
    await expect(page.locator('mat-dialog-container :focus')).toHaveCount(1);
    await page.getByTestId('field-every').fill('2');
    await page.getByTestId('recurring-expense-dialog-confirm').click();
    await expect(row.locator('[data-column="schedule"]')).toContainText(
      'Tous les 2 mois, le dernier vendredi',
    );

    await rowAction(page, row, 'pause');
    await expect(row.locator('[data-column="state"]')).toContainText('En pause');

    // Deleted asking nothing: the toast takes it back, « Récemment supprimés » for a day.
    await rowAction(page, row, 'delete');
    await expect(page.getByTestId('confirm-run')).toHaveCount(0);
    await expect(toast(page)).toContainText('est supprimé');
    await expect(row).toHaveCount(0);
  } finally {
    await tidy(page, expenseId);
  }
});
