// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Locator, type Page, test } from '@playwright/test';
import { signIn } from './session';
import { toast } from './toast';
import { wcagViolations } from './axe';

// G10 inventory through the real stack: in the seeded company, a product made for the run keeps stock; the owner
// files a location under the default one of the company's default establishment, receives ten pieces there, a
// delivery note of three validated through the API takes them out of that default location, and cancelling it puts
// them back. One database is shared by the whole suite and movements are never deleted, so the product, the customer
// and the location are unique to the run and retired afterwards.
const CSRF = '0123456789abcdef0123456789abcdef';

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
const quantity = (row: Locator): Locator => row.getByRole('cell').nth(3);

test('stock received at a location leaves with a validated delivery note and returns when it is cancelled', async ({
  page,
}) => {
  const run = Date.now().toString(36).toUpperCase();
  const reference = `E2E-STK-${run}`;
  const customerNumber = `E2E-STK-${run}`;
  const locationCode = `E2E-${run}`;
  await signIn(page);
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
    await page.getByTestId('field-productId').click();
    await page.getByRole('option', { name: `${reference} — Carton ${reference}` }).click();
    await page.getByTestId('field-locationId').click();
    await page.getByRole('option', { name: defaultLocation, exact: true }).click();
    await page.getByTestId('field-quantity').fill('10');
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('stock-movement-save').click();
    await expect(toast(page)).toContainText('Le mouvement a été enregistré.');
    const row = page.getByTestId(`stock-${reference}-${code}`);
    await expect(quantity(row)).toHaveText('10');
    expect(await wcagViolations(page)).toEqual([]);

    const noteId = await deliver(page, fixture);
    await page.reload();
    await expect(quantity(row)).toHaveText('7');

    await cancel(page, noteId);
    await page.reload();
    await expect(quantity(row)).toHaveText('10');

    await page.getByTestId(`stock-movements-${reference}-${code}`).click();
    await expect(page).toHaveURL(new RegExp(`/stock/movements\\?productId=${fixture.productId}$`));
    const movements = page.getByTestId('stock-movements-table');
    await expect(movements.getByRole('row')).toHaveCount(4);
    await expect(movements).toContainText(/Bon de livraison|Delivery note/);
    expect(await wcagViolations(page)).toEqual([]);
  } finally {
    await retire(page, reference, customerNumber, locationCode);
  }
});
