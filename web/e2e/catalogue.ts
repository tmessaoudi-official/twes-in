// SPDX-License-Identifier: AGPL-3.0-or-later
import type { Page } from '@playwright/test';

/** The double-submit token the specs send; any value the page's own cookie echoes is accepted. */
const CSRF = '0123456789abcdef0123456789abcdef';

/** A GS1 code with its check digit: weights 3 and 1 alternating from the digit next to it. */
export function gtin(body: string): string {
  const sum = [...body]
    .reverse()
    .reduce((total, digit, place) => total + Number(digit) * (place % 2 === 0 ? 3 : 1), 0);
  return `${body}${(10 - (sum % 10)) % 10}`;
}

/** Creates a goods product through the API, as another screen would have; its id. */
export async function aProduct(page: Page, reference: string): Promise<string> {
  return page.evaluate(
    async ([csrf, productReference]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}`;
      const options = (await (await fetch(`${base}/product-options`)).json()) as {
        units: { id: string; code: string }[];
      };
      const unit = options.units.find((each) => each.code === 'C62') ?? options.units[0];
      const created = await fetch(`${base}/products`, {
        method: 'POST',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: JSON.stringify({
          reference: productReference,
          name: `Vis ${productReference}`,
          kind: 'goods',
          unitId: unit.id,
          unitPriceNet: '1',
        }),
      });
      if (!created.ok) throw new Error(`creating ${productReference} answered ${created.status}`);
      return ((await created.json()) as { id: string }).id;
    },
    [CSRF, reference] as const,
  );
}

/** Creates an individual customer through the API, and says its id; one a document names stays after the run. */
export async function aCustomer(page: Page, number: string): Promise<string> {
  return page.evaluate(
    async ([csrf, customerNumber]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const created = await fetch(`/api/companies/${me.company.id}/customers`, {
        method: 'POST',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: JSON.stringify({
          number: customerNumber,
          kind: 'individual',
          taxRegime: 'standard',
          name: `Client ${customerNumber}`,
        }),
      });
      if (!created.ok) throw new Error(`creating ${customerNumber} answered ${created.status}`);
      return ((await created.json()) as { id: string }).id;
    },
    [CSRF, number] as const,
  );
}

/** Deletes a record the run made, through the API, once nothing names it; it is gone for good a day later. */
export async function removed(page: Page, subject: string, id: string): Promise<void> {
  await page.evaluate(
    async ([csrf, subject, id]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const response = await fetch(`/api/companies/${me.company.id}/record-removals`, {
        method: 'POST',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: JSON.stringify({ subject, ids: [id] }),
      });
      if (!response.ok) throw new Error(`deleting ${subject} ${id} answered ${response.status}`);
    },
    [CSRF, subject, id] as const,
  );
}

/** Creates the run's vendor through the API; its id. */
export async function aVendor(page: Page, number: string): Promise<string> {
  return page.evaluate(
    async ([csrf, vendorNumber]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const created = await fetch(`/api/companies/${me.company.id}/vendors`, {
        method: 'POST',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: JSON.stringify({ number: vendorNumber, name: `Quincaillerie ${vendorNumber}` }),
      });
      if (!created.ok) throw new Error(`creating ${vendorNumber} answered ${created.status}`);
      return ((await created.json()) as { id: string }).id;
    },
    [CSRF, number] as const,
  );
}

/** Retires the run's vendor, which the run's purchase order still names, so it cannot be deleted. */
export async function retireVendor(page: Page, id: string): Promise<void> {
  await page.evaluate(
    async ([csrf, vendorId]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const address = `/api/companies/${me.company.id}/vendors/${vendorId}`;
      // The write shape has no id: a body naming one is refused.
      const { id: _, ...fields } = (await (await fetch(address)).json()) as Record<string, unknown>;
      const revised = await fetch(address, {
        method: 'PUT',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: JSON.stringify({ ...fields, isActive: false }),
      });
      if (!revised.ok) throw new Error(`retiring ${vendorId} answered ${revised.status}`);
    },
    [CSRF, id] as const,
  );
}

/** Frees the run's codes and retires its products, so the next run can scan the same shelf. */
export async function forget(page: Page, ids: string[]): Promise<void> {
  await page.evaluate(
    async ([csrf, productIds]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}`;
      for (const id of productIds) {
        // The codes name the product's version they were read at, and move it: the retirement reads it again after.
        const { version } = (await (await fetch(`${base}/products/${id}`)).json()) as {
          version: number;
        };
        const cleared = await fetch(`${base}/products/${id}/barcodes`, {
          method: 'PUT',
          headers: { 'content-type': 'application/json', 'csrf-token': csrf },
          body: JSON.stringify({ barcodes: [], version }),
        });
        if (!cleared.ok) throw new Error(`clearing the codes of ${id} answered ${cleared.status}`);
        const {
          id: _,
          barcodes: __,
          ...fields
        } = (await (await fetch(`${base}/products/${id}`)).json()) as Record<string, unknown>;
        const retired = await fetch(`${base}/products/${id}`, {
          method: 'PUT',
          headers: { 'content-type': 'application/json', 'csrf-token': csrf },
          body: JSON.stringify({ ...fields, isActive: false }),
        });
        if (!retired.ok) throw new Error(`retiring ${id} answered ${retired.status}`);
      }
    },
    [CSRF, ids] as const,
  );
}

/** Gives a product its codes through the API, as its codes tab would. */
export async function withCodes(page: Page, id: string, codes: readonly string[]): Promise<void> {
  await page.evaluate(
    async ([csrf, productId, barcodes]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const product = `/api/companies/${me.company.id}/products/${productId}`;
      // The codes name the product's version they were read at.
      const { version } = (await (await fetch(product)).json()) as { version: number };
      const saved = await fetch(`${product}/barcodes`, {
        method: 'PUT',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: JSON.stringify({
          version,
          barcodes: barcodes.map((code, at) => ({
            role: at === 0 ? 'unit' : 'internal',
            code,
            quantity: 1,
            supplierId: null,
          })),
        }),
      });
      if (!saved.ok) throw new Error(`giving ${productId} its codes answered ${saved.status}`);
    },
    [CSRF, id, codes] as const,
  );
}

/** Keeps stock of the product, or stops keeping it, through the product's own setting. */
export async function stockKept(page: Page, productId: string, kept: boolean): Promise<void> {
  await page.evaluate(
    async ([csrf, id, on]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}/settings/article.stock_tracking`;
      const response = on
        ? await fetch(base, {
            method: 'PUT',
            headers: { 'content-type': 'application/json', 'csrf-token': csrf },
            body: JSON.stringify({ level: 'product', value: true, productId: id }),
          })
        : await fetch(`${base}?level=product&productId=${id}`, {
            method: 'DELETE',
            headers: { 'csrf-token': csrf },
          });
      if (!response.ok) throw new Error(`the stock setting answered ${response.status}`);
    },
    [CSRF, productId, kept] as const,
  );
}

/** Receives goods at a cost through the API, as another tab or member would: the open page hears it live. */
export async function receivedAt(page: Page, productId: string, unitCost: string): Promise<void> {
  await page.evaluate(
    async ([csrf, id, cost]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}`;
      const locations = (await (await fetch(`${base}/stock-locations`)).json()) as
        { id: string }[] | { member: { id: string }[] };
      const site = (Array.isArray(locations) ? locations : locations.member)[0];
      const received = await fetch(`${base}/stock-movements`, {
        method: 'POST',
        headers: {
          'content-type': 'application/json',
          'csrf-token': csrf,
          'idempotency-key': crypto.randomUUID(),
        },
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
