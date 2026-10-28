// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { wcagViolations } from './axe';
import { aProduct, forget, stockKept } from './catalogue';
import { inACompany, signIn } from './session';

// Decisions Log 2026-10-28 00:13: « À surveiller › Sous zéro » holds every product a sale took below zero at a place,
// reorder point or not, with « Compter » beside it opening the count there; a count takes it off.
const CSRF = '0123456789abcdef0123456789abcdef';

/** Far more than any other run leaves short, so the row sorts first: the subject is most short first. */
const SOLD = '999';

/** A validated delivery note of `SOLD` pieces of the product, none of which was ever received; the place it left. */
async function soldWithoutStock(page: Page, productId: string, number: string): Promise<string> {
  return page.evaluate(
    async ([csrf, id, customerNumber, quantity]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}`;
      const send = async (method: string, url: string, body: unknown): Promise<unknown> => {
        const answered = await fetch(url, {
          method,
          headers: {
            'content-type': 'application/json',
            'csrf-token': csrf,
            'idempotency-key': crypto.randomUUID(),
          },
          body: body === null ? undefined : JSON.stringify(body),
        });
        if (!answered.ok) throw new Error(`${method} ${url} answered ${answered.status}`);
        return answered.status === 204 ? null : answered.json();
      };
      const customer = (await send('POST', `${base}/customers`, {
        number: customerNumber,
        kind: 'individual',
        customerGroupId: null,
        taxRegime: 'standard',
        name: `Client ${customerNumber}`,
        legalName: null,
        identifiers: {},
        email: null,
        phone: null,
        website: null,
        billingAddressLine1: 'Rue de Rome',
        billingAddressLine2: null,
        billingPostalCode: '1000',
        billingCity: 'Tunis',
        billingCountryCode: 'TN',
        shippingAddressLine1: null,
        shippingAddressLine2: null,
        shippingPostalCode: null,
        shippingCity: null,
        shippingCountryCode: null,
        defaultTaxComponentIds: [],
        defaultDiscountRate: null,
        notes: null,
        isActive: true,
        customFields: {},
      })) as { id: string };
      const noteOptions = (await (await fetch(`${base}/delivery-note-options`)).json()) as {
        establishments: { id: string; isDefault: boolean }[];
      };
      const establishment = noteOptions.establishments.find((row) => row.isDefault);
      if (!establishment) throw new Error('the company has no default establishment');
      const options = (await (await fetch(`${base}/product-options`)).json()) as {
        units: { id: string; code: string }[];
      };
      const note = (await send('POST', `${base}/delivery-notes`, {
        customerId: customer.id,
        establishmentId: establishment.id,
        lines: [
          {
            productId: id,
            description: 'Vis',
            quantity,
            unitId: options.units.find((row) => row.code === 'C62')?.id,
            unitPriceNet: '1',
            taxComponentIds: [],
          },
        ],
      })) as { id: string };
      await send('POST', `${base}/delivery-notes/${note.id}/validate`, null);
      const moved = (await (
        await fetch(`${base}/stock-movements?productId[]=${id}`, {
          headers: { Accept: 'application/json' },
        })
      ).json()) as { locationId: string }[] | { member: { locationId: string }[] };
      const [first] = Array.isArray(moved) ? moved : moved.member;
      if (!first) throw new Error('the note moved no stock');
      return first.locationId;
    },
    [CSRF, productId, number, SOLD] as const,
  );
}

/** Counts the product at the place through the API, so a run that stops half way leaves nothing short behind it. */
async function countedAt(
  page: Page,
  productId: string,
  locationId: string,
  quantity: string,
): Promise<void> {
  await page.evaluate(
    async ([csrf, id, location, found]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const answered = await fetch(`/api/companies/${me.company.id}/stock-movements`, {
        method: 'POST',
        headers: {
          'content-type': 'application/json',
          'csrf-token': csrf,
          'idempotency-key': crypto.randomUUID(),
        },
        body: JSON.stringify({
          operation: 'count',
          productId: id,
          locationId: location,
          quantity: found,
        }),
      });
      if (!answered.ok) throw new Error(`counting ${id} answered ${answered.status}`);
    },
    [CSRF, productId, locationId, quantity] as const,
  );
}

test('a sale without stock puts the product « Sous zéro », and counting it there takes it off', async ({
  page,
}) => {
  const run = Date.now().toString(36).toUpperCase();
  const reference = `ZERO-${run}`;
  await signIn(page);
  await inACompany(page, CSRF);
  const ids: string[] = [];
  let place: string | null = null;
  try {
    ids.push(await aProduct(page, reference));
    await stockKept(page, ids[0], true);
    place = await soldWithoutStock(page, ids[0], `CLI-Z${run}`);

    await page.goto('/watch');
    const card = page.getByTestId('watch-subject-stock.below_zero');
    await expect(card).toBeVisible();
    await card.click();
    await expect(page).toHaveURL(/\/watch\/stock\.below_zero/);
    await page.goto('/watch/stock.below_zero?size=100');

    const row = page.getByTestId('watch-table').getByRole('row').filter({ hasText: reference });
    await expect(row).toHaveCount(1);
    await expect(row.locator('[data-column="onHand"]')).toHaveText(`-${SOLD}`);
    expect(await wcagViolations(page)).toEqual([]);

    // « Compter » opens count mode at that place, the product's line waiting for what is there.
    await row.locator('[data-testid^="row-action-count-"]').click();
    await expect(page).toHaveURL(new RegExp(`/stock/locations/${place}\\?product=${ids[0]}`));
    await expect(page.getByTestId('stock-count-0')).toContainText(reference);
    await page.getByTestId('stock-count-0-counted').fill('0');
    await page.getByTestId('stock-count-record').click();
    await expect(page.getByTestId('stock-count-empty')).toBeVisible();

    await page.goto('/watch/stock.below_zero?size=100');
    await expect(page.getByTestId('watch-subject-page')).toBeVisible();
    await expect(
      page.getByTestId('watch-table').getByRole('row').filter({ hasText: reference }),
    ).toHaveCount(0);
  } finally {
    if (ids[0] !== undefined && place !== null) await countedAt(page, ids[0], place, '0');
    await forget(page, ids);
  }
});
