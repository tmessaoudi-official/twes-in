// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { signIn } from './session';
import { wcagViolations } from './axe';

// G9 through the real stack: in the seeded Tunisian company, the owner files a category, adds an expense under it with
// VAT at 19 %, attaches a receipt and reads it back, records the expense and pays it. One database is shared by the
// whole suite, so the names are unique to the run; the category is deactivated at the end, and the paid expense stays,
// as a paid expense does.
const CSRF = '0123456789abcdef0123456789abcdef';
const PDF = '%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n';

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
          }),
        });
        if (!revised.ok) throw new Error(`retiring ${categoryName} answered ${revised.status}`);
      }
    },
    [CSRF, name] as const,
  );
}

test('an expense is filed with its VAT and receipt, recorded, then paid', async ({ page }) => {
  const run = Date.now().toString(36).toUpperCase();
  const category = `Carburant ${run}`;
  const description = `Gasoil ${run}`;
  await signIn(page);
  try {
    await page.goto('/expenses/categories');
    await page.getByTestId('expense-category-add').click();
    await page.getByTestId('field-name').fill(category);
    await page.getByTestId('expense-category-save').click();
    await expect(page.getByTestId(`expense-category-${category}`)).toBeVisible();

    await page.getByTestId('expenses-tab').click();
    await page.getByTestId('expense-add').click();
    await expect(page).toHaveURL(/\/expenses\/new$/);
    await page.getByTestId('field-description').fill(description);
    await page.getByTestId('field-reference').fill(`F-${run}`);
    await page.getByTestId('field-categoryId').click();
    await page.getByRole('option', { name: category }).click();
    await page.getByTestId('field-amountNet').fill('100');
    await page.getByTestId('field-taxComponentId').click();
    await page.getByRole('option', { name: /\(19 %\)$/ }).click();
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('expense-save').click();

    await expect(page).toHaveURL(/\/expenses\/[0-9a-f-]{36}$/);
    await expect(page.getByTestId('expense-title')).toContainText(description);
    await expect(page.getByTestId('expense-tax')).toContainText('19');
    await expect(page.getByTestId('expense-gross')).toContainText('119');
    await expect(page.getByTestId('expense-attachments-empty')).toBeVisible();

    await page.getByTestId('expense-attach').setInputFiles({
      name: 'recu.pdf',
      mimeType: 'application/pdf',
      buffer: Buffer.from(PDF),
    });
    const link = page.getByTestId('expense-attachment-open-recu.pdf');
    await expect(link).toBeVisible();
    // Playwright's own request context does not carry the session cookie here: read the file from inside the page.
    const read = await page.evaluate(
      async (href) => {
        const response = await fetch(href);
        return {
          status: response.status,
          type: response.headers.get('content-type'),
          body: await response.text(),
        };
      },
      (await link.getAttribute('href')) ?? '',
    );
    expect(read).toEqual({ status: 200, type: 'application/pdf', body: PDF });
    expect(await wcagViolations(page)).toEqual([]);

    await page.getByTestId('expense-record').click();
    await expect(page.getByTestId('expense-fixed')).toBeVisible();
    await expect(page.getByTestId('expense-detach-recu.pdf')).toHaveCount(0);
    await page.getByTestId('field-paymentMethod').click();
    await page.getByRole('option', { name: /esp[eè]ces|cash/i }).click();
    await page.getByTestId('expense-pay').click();
    await expect(page.getByTestId('expense-paid')).toBeVisible();
    expect(await wcagViolations(page)).toEqual([]);

    // The API pages this list and the shared company outgrows one page, so the row is searched for rather than
    // expected among the newest few.
    await page.goto('/expenses');
    await page.getByTestId('list-filter').fill(description);
    await expect(page.getByTestId('expenses-table')).toContainText(description);
  } finally {
    await retire(page, category);
  }
});
