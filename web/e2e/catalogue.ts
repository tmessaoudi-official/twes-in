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

/** Frees the run's codes and retires its products, so the next run can scan the same shelf. */
export async function forget(page: Page, ids: string[]): Promise<void> {
  await page.evaluate(
    async ([csrf, productIds]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}`;
      for (const id of productIds) {
        const cleared = await fetch(`${base}/products/${id}/barcodes`, {
          method: 'PUT',
          headers: { 'content-type': 'application/json', 'csrf-token': csrf },
          body: JSON.stringify({ barcodes: [] }),
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
      const saved = await fetch(`/api/companies/${me.company.id}/products/${productId}/barcodes`, {
        method: 'PUT',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: JSON.stringify({
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
