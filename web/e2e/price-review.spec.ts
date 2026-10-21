// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { aProduct, forget, stockKept } from './catalogue';
import { inACompany, signIn } from './session';
import { toast } from './toast';
import { runDigits } from './run';

// docs/SPEC.md § 7, cost and price: a receipt that moves a product's cost under a kept price says the margin it moved,
// offers the price that keeps the margin, and every open screen shows it at once, with no reload.
const CSRF = '0123456789abcdef0123456789abcdef';

/** Receives goods at a cost through the API, as another tab or member would: the open page hears it live. */
async function receivedAt(page: Page, productId: string, unitCost: string): Promise<void> {
  await page.evaluate(
    async ([csrf, id, cost]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}`;
      const locations = (await (await fetch(`${base}/stock-locations`)).json()) as
        { id: string }[] | { member: { id: string }[] };
      const site = (Array.isArray(locations) ? locations : locations.member)[0];
      const received = await fetch(`${base}/stock-movements`, {
        method: 'POST',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: JSON.stringify({
          operation: 'receive',
          productId: id,
          locationId: site.id,
          quantity: '1',
          unitCost: cost,
          applyCost: 'last',
        }),
      });
      if (!received.ok) throw new Error(`receiving ${id} at ${cost} answered ${received.status}`);
    },
    [CSRF, productId, unitCost] as const,
  );
}

test('a receipt that moves the margin offers the price that keeps it, and the product page shows it live', async ({
  page,
}) => {
  // Two receipts, two settlements and the clean-up.
  test.setTimeout(60_000);
  const run = runDigits();
  const reference = `PRV-${run}`;
  await signIn(page);
  await inACompany(page, CSRF);
  const ids: string[] = [];
  try {
    // Sold at 1, first bought at 0.5: a first cost moves no margin, so nothing is said yet.
    ids.push(await aProduct(page, reference));
    await stockKept(page, ids[0], true);
    await receivedAt(page, ids[0], '0.5');

    await page.goto('/stock');
    await page.getByTestId('stock-receive').click();
    await page.getByTestId('field-productId').fill(reference);
    await page.getByRole('option', { name: `${reference} · Vis ${reference}` }).click();
    await page.getByTestId('field-quantity').fill('1');
    await page.getByTestId('field-unitCost').fill('0,6');
    await page.getByTestId('field-applyCost').click();
    await page.getByRole('option', { name: 'Prendre le coût de cette réception' }).click();
    await page.getByTestId('stock-movement-save').click();

    // The receipt is recorded, and the margin it moved is said at once, with the price that keeps it a click away.
    await expect(toast(page)).toContainText(
      `La marge de Vis ${reference} est passée de 50,0 % à 40,0 %.`,
    );
    await toast(page).getByRole('button', { name: 'Ajuster le prix' }).click();
    await expect(toast(page)).toContainText(`Prix de Vis ${reference} ajusté à`);

    await page.goto(`/products/${ids[0]}`);
    await expect(page.getByTestId('field-unitPriceNet')).toHaveValue(/^1[,.]2/);
    await expect(page.getByTestId('product-price-review')).toHaveCount(0);

    // Another receipt while the page is open: the notice comes in without a reload, and settling it goes as it came.
    await receivedAt(page, ids[0], '0.9');
    await expect(page.getByTestId('product-price-review-margin')).toHaveText(
      'La marge est passée de 50,0 % à 25,0 %.',
    );
    await page.getByTestId('product-price-review-keep').click();
    await expect(toast(page)).toContainText(`Prix de Vis ${reference} gardé à`);
    await expect(page.getByTestId('product-price-review')).toHaveCount(0);
    await expect(page.getByTestId('field-unitPriceNet')).toHaveValue(/^1[,.]2/);
  } finally {
    await forget(page, ids);
  }
});
