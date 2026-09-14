// SPDX-License-Identifier: AGPL-3.0-or-later
import AxeBuilder from '@axe-core/playwright';
import { expect, type Page, test } from '@playwright/test';

// G5 products through the real stack: in the seeded Tunisian company, the owner files a category, creates a service
// in it in hours with the 19 % VAT by default, finds its price at the currency's three decimals, revises it, and
// finds it in the list under its category. One database is shared by the whole suite, so the names are unique to
// the run, and the product is deactivated (products are never deleted) and taken out of the category before the
// category is deleted.
const EMAIL = process.env['E2E_EMAIL'] ?? 'operator@twes.local';
const PASSWORD = process.env['E2E_PASSWORD'] ?? 'twes-operator-dev';
const CSRF = '0123456789abcdef0123456789abcdef';

async function signIn(page: Page): Promise<void> {
  await page.goto('/login');
  await page.getByTestId('email').fill(EMAIL);
  await page.getByTestId('password').fill(PASSWORD);
  await page.getByTestId('submit').click();
  await expect(page).toHaveURL(/\/$/);
}

async function wcagViolations(page: Page): Promise<string[]> {
  const axe = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
    .analyze();
  return axe.violations.map((violation) => violation.id);
}

/** Deactivates the run's product and takes it out of its category, then deletes the category. */
async function retire(page: Page, reference: string, categoryName: string): Promise<void> {
  await page.evaluate(
    async ([csrf, productReference, category]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}`;
      const products = (await (await fetch(`${base}/products`)).json()) as {
        id: string;
        reference: string;
      }[];
      const product = products.find((row) => row.reference === productReference);
      if (product) {
        // The write shape has no id: a body naming one is refused, and the category could then not be deleted.
        const { id, ...fields } = product;
        const revised = await fetch(`${base}/products/${id}`, {
          method: 'PUT',
          headers: { 'content-type': 'application/json', 'csrf-token': csrf },
          body: JSON.stringify({ ...fields, categoryId: null, isActive: false }),
        });
        if (!revised.ok) throw new Error(`retiring ${productReference} answered ${revised.status}`);
      }
      const categories = (await (await fetch(`${base}/product-categories`)).json()) as {
        id: string;
        name: string;
      }[];
      const found = categories.find((row) => row.name === category);
      if (found) {
        const deleted = await fetch(`${base}/product-categories/${found.id}`, {
          method: 'DELETE',
          headers: { 'csrf-token': csrf },
        });
        if (!deleted.ok) throw new Error(`deleting ${category} answered ${deleted.status}`);
      }
    },
    [CSRF, reference, categoryName] as const,
  );
}

test('a product is filed in a category, priced at the currency scale and revised', async ({
  page,
}) => {
  const run = Date.now().toString(36).toUpperCase();
  const categoryName = `E2E ${run}`;
  const reference = `E2E-${run}`;
  await signIn(page);
  try {
    await page.goto('/products/categories');
    await page.getByTestId('product-category-add').click();
    await page.getByTestId('field-name').fill(categoryName);
    await page.getByTestId('product-category-save').click();
    await expect(page.getByTestId(`product-category-${categoryName}`)).toBeVisible();
    expect(await wcagViolations(page)).toEqual([]);

    await page.goto('/products/new');
    await page.getByTestId('field-reference').fill(reference);
    await page.getByTestId('field-name').fill(`Audit fiscal ${run}`);
    await page.getByTestId('field-kind').click();
    await page.getByRole('option', { name: 'Service' }).click();
    await page.getByTestId('field-categoryId').click();
    await page.getByRole('option', { name: categoryName }).click();
    await page.getByTestId('field-unitId').click();
    await page.getByRole('option', { name: /^HUR · / }).click();
    await page.getByTestId('field-unitPriceNet').fill('120.5');
    await page.getByRole('checkbox', { name: /19/ }).check();
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('product-save').click();

    await expect(page).toHaveURL(/\/products\/[0-9a-f-]{36}$/);
    await expect(page.getByTestId('product-title')).toContainText(reference);
    await expect(page.getByTestId('field-unitPriceNet')).toHaveValue('120.500');

    await page.getByTestId('field-unitPriceNet').fill('135');
    await page.getByTestId('product-save').click();
    await expect(page.getByTestId('product-saved')).toBeVisible();

    await page.goto('/products');
    await expect(page.getByTestId(`product-${reference}`)).toContainText(categoryName);
    await expect(page.getByTestId(`product-${reference}`)).toContainText('135.000');
    await expect(page.getByTestId(`product-${reference}`)).toContainText('HUR');
  } finally {
    await retire(page, reference, categoryName);
  }
});
