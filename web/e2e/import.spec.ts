// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { aProduct, forget, stockKept } from './catalogue';
import { inACompany, signIn } from './session';
import { toast } from './toast';

// Row 59 through the real stack: the owner opens the import screen for customers, takes the empty file, previews a
// file the rules refuse and reads why, then previews and imports a clean one. One database is shared by the whole
// suite, so the numbers are unique to the run and the customer it creates is deactivated at the end.
const CSRF = '0123456789abcdef0123456789abcdef';
const RUN = Date.now().toString(36).toUpperCase();
const NUMBER = `IMP-${RUN}`;

/** Deactivates the customer the run imported, so the shared company does not grow a row per run. */
async function retire(page: Page, number: string): Promise<void> {
  await page.evaluate(
    async ([csrf, customerNumber]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}`;
      const listed = (await (
        await fetch(`${base}/customers?q=${encodeURIComponent(customerNumber)}`)
      ).json()) as { member: { id: string; number: string }[] };
      const hit = listed.member.find((row) => row.number === customerNumber);
      if (!hit) return;
      const customer = (await (await fetch(`${base}/customers/${hit.id}`)).json()) as {
        id: string;
      } & Record<string, unknown>;
      // The write shape has no id: a body naming one is refused.
      const { id, ...fields } = customer;
      const revised = await fetch(`${base}/customers/${id}`, {
        method: 'PUT',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: JSON.stringify({ ...fields, isActive: false }),
      });
      if (!revised.ok) throw new Error(`retiring ${customerNumber} answered ${revised.status}`);
    },
    [CSRF, number] as const,
  );
}

async function choose(page: Page, name: string, contents: string): Promise<void> {
  await page
    .getByTestId('import-file')
    .setInputFiles({ name, mimeType: 'text/csv', buffer: Buffer.from(contents, 'utf8') });
}

test('a file is previewed before it is imported, and a refused row says why', async ({ page }) => {
  await signIn(page);
  await inACompany(page, CSRF);
  await page.goto('/imports/customers');

  // The screen is driven by the guide the API answers for this company.
  await expect(page.getByTestId('import-columns')).toBeVisible();
  await expect(page.getByTestId('import-identity')).toContainText('number');
  await expect(page.getByTestId('import-store')).toBeDisabled();

  // The empty file to fill in comes from the API, under the subject's own name.
  const [downloaded] = await Promise.all([
    page.waitForEvent('download'),
    page.getByTestId('import-template-csv').click(),
  ]);
  expect(downloaded.suggestedFilename()).toBe('customers.csv');

  // A file naming the same customer twice: the second line is one thing twice, and nothing is stored.
  await choose(
    page,
    'clients.csv',
    `number,name,kind\n${NUMBER},Quincaillerie du Lac,individual\n${NUMBER},Quincaillerie du Lac,individual\n`,
  );
  await page.getByTestId('import-preview').click();
  await expect(page.getByTestId('import-rejections')).toContainText('ligne 2');
  await expect(page.getByTestId('import-nothing-stored')).toBeVisible();
  await expect(page.getByTestId('import-store')).toBeDisabled();

  // The same file without the repeat: the preview is clean, and only then may it be imported.
  await choose(
    page,
    'clients.csv',
    `number,name,kind\n${NUMBER},Quincaillerie du Lac,individual\n`,
  );
  await page.getByTestId('import-preview').click();
  await expect(page.getByTestId('import-created')).toContainText('1');
  await expect(page.getByTestId('import-rejections')).toHaveCount(0);
  await expect(page.getByTestId('import-store')).toBeEnabled();

  await page.getByTestId('import-store').click();
  await expect(toast(page)).toContainText('1');

  // What the file asked for is a customer like any other.
  await page.goto('/customers');
  await page.getByTestId('list-filter').fill(NUMBER);
  await expect(page.getByTestId('customers-table')).toContainText('Quincaillerie du Lac');

  await retire(page, NUMBER);
});

// docs/SPEC.md § 7, 2026-09-17 (3): a product file may leave the reference out; the preview says which one each new
// product would be given. Previewed only, so nothing is stored in the shared company.
test('a product file without references is previewed with the references it would give', async ({
  page,
}) => {
  await signIn(page);
  await inACompany(page, CSRF);
  await page.goto('/imports/products');
  await expect(page.getByTestId('import-columns')).toBeVisible();

  await choose(page, 'produits.csv', `name,unit_code,unit_price_net\nCheville ${RUN},H87,0.1\n`);
  await page.getByTestId('import-preview').click();
  await expect(page.getByTestId('import-created')).toContainText('1');
  await expect(page.getByTestId('import-notes')).toContainText('Référence donnée');
  await expect(page.getByTestId('import-store')).toBeEnabled();
});

// Row 246: an opening-stock file counts or adds, and the preview says each row's stock before and after.
test('an opening-stock file is previewed with the stock each row leaves', async ({ page }) => {
  await signIn(page);
  await inACompany(page, CSRF);
  await page.goto('/');
  const id = await aProduct(page, `OUV-${RUN}`);
  await stockKept(page, id, true);
  try {
    await page.goto('/imports/opening-stock');
    await expect(page.getByTestId('import-columns')).toContainText('stock_count');
    await expect(page.getByTestId('import-switch-recount')).toBeVisible();

    await choose(page, 'stock.csv', `reference,stock_count\nOUV-${RUN},12\n`);
    await page.getByTestId('import-preview').click();
    await expect(page.getByTestId('import-created')).toContainText('1');
    await expect(page.getByTestId('import-notes')).toContainText('0 → 12');
    await expect(page.getByTestId('import-already-imported')).toHaveCount(0);
    await expect(page.getByTestId('import-store')).toBeEnabled();
  } finally {
    await forget(page, [id]);
  }
});

// Row 246: a product file may bring quantities, and « Ignorer les quantités » reads it for its prices alone.
test('a product file adds stock, or is read for its prices alone', async ({ page }) => {
  await signIn(page);
  await inACompany(page, CSRF);
  await page.goto('/');
  const id = await aProduct(page, `QTE-${RUN}`);
  await stockKept(page, id, true);
  try {
    await page.goto('/imports/products');
    await expect(page.getByTestId('import-columns')).toContainText('stock_add');
    await page.getByTestId('import-mode').getByText('mettre à jour').click();

    await choose(page, 'produits.csv', `reference,name,stock_add\nQTE-${RUN},Vis QTE,3\n`);
    await page.getByTestId('import-preview').click();
    await expect(page.getByTestId('import-updated')).toContainText('1');
    await expect(page.getByTestId('import-notes')).toContainText('0 → 3');

    // The checkbox's host spans the row: a click at its centre lands beside the label and ticks nothing.
    await page.getByTestId('import-switch-ignore_quantities').locator('input').check();
    await expect(page.getByTestId('import-store')).toBeDisabled();
    await page.getByTestId('import-preview').click();
    await expect(page.getByTestId('import-updated')).toContainText('1');
    await expect(page.getByTestId('import-notes')).toHaveCount(0);
  } finally {
    await forget(page, [id]);
  }
});
