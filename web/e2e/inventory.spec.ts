// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Locator, type Page, test } from '@playwright/test';
import { inACompany, signIn } from './session';
import { toast } from './toast';
import { wcagViolations } from './axe';
import { rowAction } from './rows';

// G10 inventory through the real stack: in the seeded company, a product made for the run keeps stock; the owner
// files a location under the default one of the company's default establishment, receives ten pieces there, a
// delivery note of three validated through the API takes them out of that default location, and cancelling it puts
// them back. One database is shared by the whole suite and movements are never deleted, so the product, the customer
// and the location are unique to the run and retired afterwards.
const CSRF = '0123456789abcdef0123456789abcdef';
/** The smallest PNG there is, one transparent pixel: what a photo of a loss is, as far as the API can tell. */
const ONE_PIXEL_PNG =
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

interface Fixture {
  productId: string;
  customerId: string;
  establishment: { id: string; code: string; name: string };
}

/** A piece-counted product whose stock is kept, and a customer to deliver it to, through the API. */
async function prepare(page: Page, reference: string, customerNumber: string): Promise<Fixture> {
  return page.evaluate(
    async ([csrf, productReference, number]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}`;
      const send = async (method: string, url: string, body: unknown): Promise<unknown> => {
        const response = await fetch(url, {
          method,
          headers: { 'content-type': 'application/json', 'csrf-token': csrf },
          body: JSON.stringify(body),
        });
        if (!response.ok) throw new Error(`${method} ${url} answered ${response.status}`);
        return response.json();
      };
      const options = (await (await fetch(`${base}/product-options`)).json()) as {
        units: { id: string; code: string }[];
      };
      const unit = options.units.find((row) => row.code === 'C62');
      if (!unit) throw new Error('the company has no C62 unit');
      const product = (await send('POST', `${base}/products`, {
        reference: productReference,
        name: `Carton ${productReference}`,
        description: null,
        kind: 'goods',
        unitId: unit.id,
        unitPriceNet: '10',
        costPrice: null,
        categoryId: null,
        barcode: null,
        defaultTaxComponentIds: [],
        isActive: true,
        customFields: {},
      })) as { id: string };
      await send('PUT', `${base}/settings/article.stock_tracking`, {
        level: 'product',
        value: true,
        productId: product.id,
      });
      const customer = (await send('POST', `${base}/customers`, {
        number,
        kind: 'individual',
        customerGroupId: null,
        taxRegime: 'standard',
        name: `Client ${number}`,
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
        establishments: { id: string; code: string; name: string; isDefault: boolean }[];
      };
      const establishment = noteOptions.establishments.find((row) => row.isDefault);
      if (!establishment) throw new Error('the company has no default establishment');
      return {
        productId: product.id,
        customerId: customer.id,
        establishment: { id: establishment.id, code: establishment.code, name: establishment.name },
      };
    },
    [CSRF, reference, customerNumber] as const,
  );
}

/** A validated delivery note of three pieces of the product, from the default establishment; its id. */
async function deliver(page: Page, fixture: Fixture): Promise<string> {
  return page.evaluate(
    async ([csrf, { productId, customerId, establishment }]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}`;
      const options = (await (await fetch(`${base}/product-options`)).json()) as {
        units: { id: string; code: string }[];
      };
      const created = await fetch(`${base}/delivery-notes`, {
        method: 'POST',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: JSON.stringify({
          customerId,
          establishmentId: establishment.id,
          lines: [
            {
              productId,
              description: 'Cartons',
              quantity: '3',
              unitId: options.units.find((row) => row.code === 'C62')?.id,
              unitPriceNet: '10',
              taxComponentIds: [],
            },
          ],
        }),
      });
      if (!created.ok) throw new Error(`drafting the note answered ${created.status}`);
      const { id } = (await created.json()) as { id: string };
      const validated = await fetch(`${base}/delivery-notes/${id}/validate`, {
        method: 'POST',
        headers: { 'csrf-token': csrf },
      });
      if (!validated.ok) throw new Error(`validating the note answered ${validated.status}`);
      return id;
    },
    [CSRF, fixture] as const,
  );
}

async function cancel(page: Page, noteId: string): Promise<void> {
  await page.evaluate(
    async ([csrf, id]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const response = await fetch(`/api/companies/${me.company.id}/delivery-notes/${id}/cancel`, {
        method: 'POST',
        headers: { 'csrf-token': csrf },
      });
      if (!response.ok) throw new Error(`cancelling the note answered ${response.status}`);
    },
    [CSRF, noteId] as const,
  );
}

/** Deactivates the run's product and customer, forgets its stock setting, and deletes its empty location. */
async function retire(
  page: Page,
  reference: string,
  customerNumber: string,
  locationCode: string,
): Promise<void> {
  await page.evaluate(
    async ([csrf, productReference, number, code]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      // The list is paged: the customer is found by its number, then read whole as the single record it is.
      const customerNumbered = async (company: string, wanted: string) => {
        const listed = (await (
          await fetch(`${company}/customers?q=${encodeURIComponent(wanted)}`)
        ).json()) as { member: { id: string; number: string }[] };
        const hit = listed.member.find((row) => row.number === wanted);
        return hit === undefined
          ? undefined
          : ((await (await fetch(`${company}/customers/${hit.id}`)).json()) as {
              id: string;
              number: string;
            });
      };
      const base = `/api/companies/${me.company.id}`;
      const headers = { 'content-type': 'application/json', 'csrf-token': csrf };
      const check = (response: Response, what: string): void => {
        if (!response.ok) throw new Error(`${what} answered ${response.status}`);
      };
      // The write shapes have no id: a body naming one is refused.
      // The list is paged: the product is found by its reference, then read whole as the single record it is.
      const listed = (await (
        await fetch(`${base}/products?q=${encodeURIComponent(productReference)}`)
      ).json()) as { member: { id: string; reference: string }[] };
      const hit = listed.member.find((row) => row.reference === productReference);
      const product =
        hit === undefined
          ? undefined
          : ((await (await fetch(`${base}/products/${hit.id}`)).json()) as {
              id: string;
              reference: string;
            });
      if (product) {
        const { id, ...fields } = product;
        check(
          await fetch(`${base}/settings/article.stock_tracking?level=product&productId=${id}`, {
            method: 'DELETE',
            headers,
          }),
          'forgetting the stock setting',
        );
        check(
          await fetch(`${base}/products/${id}`, {
            method: 'PUT',
            headers,
            body: JSON.stringify({ ...fields, isActive: false }),
          }),
          `retiring ${productReference}`,
        );
      }
      const customer = await customerNumbered(base, number);
      if (customer) {
        const { id, ...fields } = customer;
        check(
          await fetch(`${base}/customers/${id}`, {
            method: 'PUT',
            headers,
            body: JSON.stringify({ ...fields, isActive: false }),
          }),
          `retiring ${number}`,
        );
      }
      const locations = (await (await fetch(`${base}/stock-locations`)).json()) as {
        id: string;
        code: string;
      }[];
      const location = locations.find((row) => row.code === code);
      if (location) {
        check(
          await fetch(`${base}/stock-locations/${location.id}`, { method: 'DELETE', headers }),
          `deleting ${code}`,
        );
      }
    },
    [CSRF, reference, customerNumber, locationCode] as const,
  );
}

/** The quantity column of a stock row: reference, product, location, quantity, unit. */
// By its column, not its position: a column added before it (the lot, 2026-09-23) moved every position after it.
const quantity = (row: Locator): Locator => row.locator('[data-column="quantity"]');

test('a delivery shared over two places lands whole at both', async ({ page }) => {
  const run = Date.now().toString(36).toUpperCase();
  const reference = `E2E-SPL-${run}`;
  const customerNumber = `E2E-SPL-${run}`;
  const locationCode = `E2E-${run}`;
  await signIn(page);
  await inACompany(page, CSRF);
  const fixture = await prepare(page, reference, customerNumber);
  const { code, name } = fixture.establishment;
  const defaultLocation = `${code} — ${name}`;
  try {
    await page.goto('/stock/locations');
    await expect(page.getByTestId(`stock-location-${code}`)).toBeVisible();
    await page.getByTestId('stock-location-add').click();
    await page.getByTestId('field-establishmentId').click();
    await page.getByRole('option', { name: defaultLocation, exact: true }).click();
    await page.getByTestId('field-code').fill(locationCode);
    await page.getByTestId('field-name').fill('Réserve e2e');
    await page.getByTestId('stock-location-save').click();
    const shelf = `${code} › ${locationCode} — Réserve e2e`;
    await expect(page.getByTestId(`stock-location-${locationCode}`)).toContainText(shelf);

    await page.goto('/stock');
    await page.getByTestId('stock-receive').click();
    await page.getByTestId('field-productId').fill(reference);
    await page.getByRole('option', { name: `${reference} · Carton ${reference}` }).click();
    await page.getByTestId('field-quantity').fill('10');
    await page.getByTestId('stock-split').click();

    // The place of the form is gone, the rows say where; nothing can be saved while some is left to place.
    await expect(page.getByTestId('field-locationId')).toHaveCount(0);
    await page.getByTestId('placement-0-quantity').fill('6');
    await expect(page.getByTestId('placement-status')).toContainText('Il reste 4 à placer');
    await page.getByTestId('stock-movement-save').click();
    await expect(page.getByTestId('placement-refused')).toBeVisible();

    await page.getByTestId('placement-1-location').click();
    await page.getByRole('option', { name: shelf, exact: true }).click();
    await page.getByTestId('placement-1-quantity').fill('4');
    await expect(page.getByTestId('placement-status')).toContainText('Tout est placé');
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('stock-movement-save').click();
    await expect(toast(page)).toContainText('Le mouvement a été enregistré.');

    await page.getByTestId('list-filter').fill(reference);
    await expect(quantity(page.getByTestId(`stock-${reference}-${code}`))).toHaveText('6');
    await expect(quantity(page.getByTestId(`stock-${reference}-${locationCode}`))).toHaveText('4');
  } finally {
    // The shelf is not deleted: a location that has seen a movement is kept (409), and this one has seen one.
    await retire(page, reference, customerNumber, '');
  }
});

test('stock received at a location leaves with a validated delivery note and returns when it is cancelled', async ({
  page,
}) => {
  // A whole delivery cycle, two accessibility scans and the history's filters: more than the default 30 s.
  test.setTimeout(60_000);
  const run = Date.now().toString(36).toUpperCase();
  const reference = `E2E-STK-${run}`;
  const customerNumber = `E2E-STK-${run}`;
  const locationCode = `E2E-${run}`;
  await signIn(page);
  await inACompany(page, CSRF);
  const fixture = await prepare(page, reference, customerNumber);
  const { code, name } = fixture.establishment;
  const defaultLocation = `${code} — ${name}`;
  try {
    await page.goto('/stock/locations');
    await expect(page.getByTestId(`stock-location-${code}`)).toBeVisible();
    await page.getByTestId('stock-location-add').click();
    await page.getByTestId('field-establishmentId').click();
    await page.getByRole('option', { name: defaultLocation, exact: true }).click();
    await page.getByTestId('field-code').fill(locationCode);
    await page.getByTestId('field-name').fill('Réserve e2e');
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('stock-location-save').click();
    await expect(page.getByTestId(`stock-location-${locationCode}`)).toContainText(
      `${code} › ${locationCode} — Réserve e2e`,
    );
    expect(await wcagViolations(page)).toEqual([]);

    await page.goto('/stock');
    await page.getByTestId('stock-receive').click();
    // Typed, not scrolled to: the picker answers the few that match, and only goods whose stock is kept.
    await page.getByTestId('field-productId').fill(reference);
    await page.getByRole('option', { name: `${reference} · Carton ${reference}` }).click();
    await page.getByTestId('field-locationId').click();
    await page.getByRole('option', { name: defaultLocation, exact: true }).click();
    await page.getByTestId('field-quantity').fill('10');
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('stock-movement-save').click();
    await expect(toast(page)).toContainText('Le mouvement a été enregistré.');
    const row = page.getByTestId(`stock-${reference}-${code}`);
    // The API pages this list and the shared company outgrows one page, so the row is searched for; the search is
    // the list's own and does not survive a reload, so it is made again after each one.
    const find = async (): Promise<void> => {
      await page.getByTestId('list-filter').fill(reference);
      await expect(row).toBeVisible();
    };
    await find();
    await expect(quantity(row)).toHaveText('10');
    expect(await wcagViolations(page)).toEqual([]);

    const noteId = await deliver(page, fixture);
    await page.reload();
    await find();
    await expect(quantity(row)).toHaveText('7');

    await cancel(page, noteId);
    await page.reload();
    await find();
    await expect(quantity(row)).toHaveText('10');

    // A loss takes goods out under a reason, which the movements then say.
    await page.getByTestId('stock-loss').click();
    await page.getByTestId('field-productId').fill(reference);
    await page.getByRole('option', { name: `${reference} · Carton ${reference}` }).click();
    await page.getByTestId('field-locationId').click();
    await page.getByRole('option', { name: defaultLocation, exact: true }).click();
    await page.getByTestId('field-quantity').fill('2');
    await page.getByTestId('field-reason').click();
    await page.getByRole('option', { name: 'Cassée', exact: true }).click();
    await page.getByTestId('field-note').fill('Tombé du comptoir');
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('stock-movement-save').click();
    await expect(toast(page)).toContainText('Le mouvement a été enregistré.');
    await page.reload();
    await find();
    await expect(quantity(row)).toHaveText('8');

    await rowAction(page, `stock-${reference}-${code}`, 'movements').click();
    await expect(page).toHaveURL(new RegExp(`/stock/movements\\?productId=${fixture.productId}$`));
    const movements = page.getByTestId('stock-movements-table');
    await expect(movements.getByRole('row')).toHaveCount(5);
    await expect(movements).toContainText(/Bon de livraison|Delivery note/);
    await expect(page.getByTestId('movement-reason')).toContainText('Cassée');
    await expect(page.getByTestId('movement-note')).toContainText('Tombé du comptoir');
    expect(await wcagViolations(page)).toEqual([]);

    // Row 74 (b): the loss keeps the photo of what broke, opened from its row, and a file attached by mistake comes off.
    await page.locator('[data-testid^="row-action-loss-files-"]').click();
    await expect(page.getByTestId('loss-files-none')).toBeVisible();
    await page.getByTestId('loss-files-add').setInputFiles({
      name: 'carton.png',
      mimeType: 'image/png',
      buffer: Buffer.from(ONE_PIXEL_PNG, 'base64'),
    });
    await expect(toast(page)).toContainText('« carton.png » est joint à la perte.');
    await expect(page.getByTestId('loss-file-open-carton.png')).toHaveAttribute(
      'href',
      /\/stock-movements\/[0-9a-f-]+\/attachments\/[0-9a-f-]+\/content$/,
    );
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('loss-files-close').click();
    await expect(page.getByTestId('movement-files')).toContainText('1 fichier joint');
    await page.locator('[data-testid^="row-action-loss-files-"]').click();
    await page.getByTestId('loss-file-remove-carton.png').click();
    await expect(page.getByTestId('loss-files-none')).toBeVisible();
    // Row 253: « Annuler » on the toast puts it back where it was; taken off again, it stays off.
    await expect(toast(page)).toContainText('« carton.png » est retiré de la perte.');
    await toast(page).getByRole('button', { name: 'Annuler' }).click();
    await expect(toast(page)).toContainText('« carton.png » est remis à la perte.');
    await expect(page.getByTestId('loss-file-open-carton.png')).toBeVisible();
    await page.getByTestId('loss-file-remove-carton.png').click();
    await expect(page.getByTestId('loss-files-none')).toBeVisible();
    await page.getByTestId('loss-files-close').click();
    await expect(page.getByTestId('movement-files')).toHaveCount(0);

    // Row 197: the product the address names combines with the list's own filters, each answered by the API.
    await page.goto(`/stock/movements?productId=${fixture.productId}&reason=broken`);
    await expect(movements.getByRole('row')).toHaveCount(2);
    await expect(page.getByTestId('movement-reason')).toContainText('Cassée');
    // The loss, and the delivery note's departure and its return when it was cancelled.
    await page.goto(`/stock/movements?productId=${fixture.productId}&source=loss,delivery_note`);
    await expect(movements.getByRole('row')).toHaveCount(4);

    // And the stock list's: one product, nothing below zero, then something that cannot be (row 197).
    const stock = page.getByTestId('stock-table');
    await page.goto(`/stock?product=${fixture.productId}&negative=no`);
    await expect(stock.getByRole('row')).toHaveCount(2);
    await expect(quantity(row)).toHaveText('8');
    await page.goto(`/stock?product=${fixture.productId}&negative=yes`);
    await expect(page.getByTestId('list-no-match')).toBeVisible();
  } finally {
    await retire(page, reference, customerNumber, locationCode);
  }
});
