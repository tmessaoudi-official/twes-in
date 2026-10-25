// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test, type Page } from '@playwright/test';
import { wcagViolations } from './axe';
import { runDigits } from './run';
import { inACompany, signIn } from './session';
import { toast } from './toast';

// A shop's past purchases come from a file, after its products and vendors: each line joins its product's cost history
// naming the vendor, and the vendor's « Achats » lists what was bought from it, the undated last.
const CSRF = '0123456789abcdef0123456789abcdef';

test('a file of past purchases fills a product cost history and its vendor « Achats », the undated last', async ({
  page,
}) => {
  test.setTimeout(90_000);
  const run = runDigits();
  const vendor = `0FRN-${run}`;
  const product = `0ACH-${run}`;
  await signIn(page);
  await inACompany(page, CSRF);
  await imported(page, 'vendors', `number,name\n${vendor},Sotumag ${run}\n`);
  await imported(
    page,
    'products',
    `reference,name,unit_price_net\n${product},Vis achetée ${run},2.5\n`,
  );

  await page.goto('/products');
  await page.getByTestId('products-import').click();
  await page.getByTestId('products-import-purchases').click();
  await expect(page).toHaveURL(/\/imports\/purchase-history$/);
  await page.getByTestId('import-file').setInputFiles({
    name: 'achats.csv',
    mimeType: 'text/csv',
    buffer: Buffer.from(
      'product_reference,vendor_number,date,invoice_number,quantity,unit_cost\n' +
        `${product},${vendor},2024-02-03,FA-12,72,1.6882\n` +
        `${product},${vendor},,,3,1.9\n` +
        `${product},${vendor},2025-13-45,,1,2\n`,
      'utf8',
    ),
  });
  await page.getByTestId('import-preview').click();
  await expect(page.getByTestId('import-created')).toContainText('3');
  await page.getByTestId('import-store').click();
  await expect(toast(page)).toContainText('3');

  // On the product: the dated line first, then the two of no known day, each naming its vendor.
  const productId = await idOf(page, `products?q=${product}`, 'reference', product);
  await page.goto(`/products/${productId}`);
  await page.getByTestId('product-tabs').getByRole('tab', { name: 'Historique des coûts' }).click();
  const lines = page.getByTestId('product-cost-history-list').locator('li');
  await expect(lines).toHaveCount(3);
  await expect(lines.nth(0)).toContainText('facture FA-12');
  await expect(lines.nth(0)).toContainText(`Sotumag ${run}`);
  await expect(lines.nth(1)).toContainText('date inconnue');
  await expect(lines.nth(2)).toContainText('date inconnue');
  expect(await wcagViolations(page), 'the cost history').toEqual([]);

  // On the vendor: « Achats » lists the same lines, each opening its product.
  await lines.nth(0).getByRole('link').click();
  await expect(page.getByTestId('vendor-title')).toContainText(vendor);
  await page.getByTestId('vendor-tabs').getByRole('tab', { name: 'Achats' }).click();
  await expect(page.getByTestId('vendor-purchases-count')).toContainText('3 lignes d’achat');
  const bought = page.getByTestId('vendor-purchases-list').locator('li');
  await expect(bought).toHaveCount(3);
  await expect(bought.nth(0)).toContainText('quantité 72');
  await expect(bought.nth(2)).toContainText('date inconnue');
  expect(await wcagViolations(page), 'the vendor « Achats »').toEqual([]);
  await bought.nth(0).getByRole('link').click();
  await expect(page).toHaveURL(new RegExp(`/products/${productId}$`));
});

/** Brings records in by file through the API, as the import screen sends one, and asserts nothing was refused. */
async function imported(page: Page, subject: string, csv: string): Promise<void> {
  const answer = await page.evaluate(
    async ([csrf, what, contents]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const form = new FormData();
      form.append('file', new Blob([contents], { type: 'text/csv' }), `${what}.csv`);
      form.append('mode', 'create');
      form.append('dryRun', '0');
      const response = await fetch(`/api/companies/${me.company.id}/imports/${what}`, {
        method: 'POST',
        headers: { 'csrf-token': csrf, 'idempotency-key': crypto.randomUUID() },
        body: form,
      });
      return { status: response.status, body: (await response.json()) as { rejected?: unknown[] } };
    },
    [CSRF, subject, csv] as const,
  );
  expect(answer.status, `importing ${subject}: ${JSON.stringify(answer.body)}`).toBe(200);
  expect(answer.body.rejected ?? []).toEqual([]);
}

/** The id of the record the list finds under that key, read from inside the page. */
async function idOf(page: Page, list: string, key: string, value: string): Promise<string> {
  const id = await page.evaluate(
    async ([address, field, wanted]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const response = await fetch(`/api/companies/${me.company.id}/${address}`, {
        headers: { accept: 'application/ld+json' },
      });
      if (!response.ok) throw new Error(`reading ${address} answered ${response.status}`);
      const page = (await response.json()) as { member: Record<string, string>[] };
      return page.member.find((row) => row[field] === wanted)?.['id'] ?? null;
    },
    [list, key, value] as const,
  );
  expect(id, `${value} in ${list}`).not.toBeNull();
  return id ?? '';
}
