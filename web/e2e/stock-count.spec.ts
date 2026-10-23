// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test';
import { wcagViolations } from './axe';
import { aProduct, forget, gtin, stockKept, withCodes } from './catalogue';
import { scan } from './scan';
import { inACompany, signIn } from './session';
import { toast } from './toast';

// docs/SPEC.md § 7, 2026-09-23 slice 8: count mode. What is scanned at a location is tallied there, and recording sets
// that location's stock of it to what was counted.
const CSRF = '0123456789abcdef0123456789abcdef';

test('count mode tallies what is scanned at a location and records it as the stock there', async ({
  page,
}) => {
  const run = Date.now().toString(36).toUpperCase();
  const reference = `CNT-${run}`;
  const code = gtin(`204${String(Date.now() % 1_000_000_000).padStart(9, '0')}`);
  await signIn(page);
  await inACompany(page, CSRF);
  const ids: string[] = [];
  try {
    ids.push(await aProduct(page, reference));
    await stockKept(page, ids[0], true);
    await withCodes(page, ids[0], [code]);

    await page.goto('/stock');
    await page.getByTestId('stock-count-tab').click();
    await expect(page.getByTestId('stock-count-empty')).toBeVisible();
    // Nothing to record yet, and « Ajouter » as tall as the code field beside it (audit 2026-10-06 V-23 d).
    await expect(page.getByTestId('stock-count-record')).toHaveCount(0);
    const heights = await page.evaluate(() =>
      [
        '[data-testid="stock-count-add"]',
        'form:has([data-testid="stock-count-code"]) .mat-mdc-text-field-wrapper',
      ].map((selector) =>
        Math.round(document.querySelector(selector)!.getBoundingClientRect().height),
      ),
    );
    expect(heights[0]).toBe(heights[1]);
    await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur());
    await scan(page, code);
    await expect(page.getByTestId('stock-count-0-counted')).toHaveValue('1');
    await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur());
    await scan(page, code);
    await expect(page.getByTestId('stock-count-0-counted')).toHaveValue('2');
    await expect(page.getByTestId('stock-count-1')).toHaveCount(0);
    expect(await wcagViolations(page)).toEqual([]);

    await page.getByTestId('stock-count-record').click();
    await expect(toast(page)).toContainText('Comptage enregistré');
    await expect(page.getByTestId('stock-count-empty')).toBeVisible();

    // Counted again, the line says what is recorded there and what recording would change, before anything is sent.
    await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur());
    await scan(page, code);
    await expect(page.getByTestId('stock-count-0-effect-expected')).toContainText('Attendu ici');
    await expect(page.getByTestId('stock-count-0-effect-expected')).toContainText('2');
    await expect(page.getByTestId('stock-count-0-effect-difference')).toContainText('−1');
    await expect(page.getByTestId('stock-count-0-effect-stray')).toHaveCount(0);
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('stock-count-0-remove').click();
    await expect(page.getByTestId('stock-count-empty')).toBeVisible();

    await page.getByTestId('stock-tab').click();
    await page.getByTestId('list-filter').fill(reference);
    const row = page.getByRole('row').filter({ hasText: reference });
    await expect(row.locator('[data-column="quantity"]')).toHaveText('2');
  } finally {
    if (ids.length > 0) await stockKept(page, ids[0], false);
    await forget(page, ids);
  }
});

// A line keeps the location it was scanned at: the same product counted on two shelves is two lines, shown under each
// shelf. Nothing is recorded, so the shelf the run made has seen no movement and is deleted afterwards.
test('a product counted on two shelves is a line under each', async ({ page }) => {
  const run = Date.now().toString(36).toUpperCase();
  const shelfCode = `CNT-${run}`;
  const code = gtin(`205${String(Date.now() % 1_000_000_000).padStart(9, '0')}`);
  await signIn(page);
  await inACompany(page, CSRF);
  const ids: string[] = [];
  try {
    ids.push(await aProduct(page, `CNL-${run}`));
    await stockKept(page, ids[0], true);
    await withCodes(page, ids[0], [code]);
    await page.evaluate(
      async ([csrf, code]) => {
        const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
        const base = `/api/companies/${me.company.id}`;
        const [site] = (await (await fetch(`${base}/stock-locations`)).json()) as {
          establishmentId: string;
        }[];
        const made = await fetch(`${base}/stock-locations`, {
          method: 'POST',
          headers: { 'content-type': 'application/json', 'csrf-token': csrf },
          body: JSON.stringify({
            establishmentId: site.establishmentId,
            parentId: null,
            kind: 'zone',
            code,
            name: 'Étagère comptée',
          }),
        });
        if (!made.ok) throw new Error(`creating ${code} answered ${made.status}`);
      },
      [CSRF, shelfCode] as const,
    );

    await page.goto('/stock/count');
    await expect(page.getByTestId('stock-count-empty')).toBeVisible();
    await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur());
    await scan(page, code);
    await expect(page.getByTestId('stock-count-0-counted')).toHaveValue('1');
    await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur());
    await scan(page, shelfCode);
    await expect(toast(page)).toContainText('Étagère comptée');
    await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur());
    await scan(page, code);
    await scan(page, code);

    await expect(
      page.getByTestId('stock-count-group-0').getByTestId('stock-count-0'),
    ).toBeVisible();
    await expect(page.getByTestId('stock-count-group-1')).toContainText('Étagère comptée');
    await expect(page.getByTestId('stock-count-0-counted')).toHaveValue('1');
    await expect(page.getByTestId('stock-count-1-counted')).toHaveValue('2');
    expect(await wcagViolations(page)).toEqual([]);
    await page.screenshot({
      path: test.info().outputPath('count-by-location.png'),
      fullPage: true,
    });
  } finally {
    if (ids.length > 0) await stockKept(page, ids[0], false);
    await forget(page, ids);
    await page.evaluate(
      async ([csrf, code]) => {
        const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
        const base = `/api/companies/${me.company.id}`;
        const locations = (await (await fetch(`${base}/stock-locations`)).json()) as {
          id: string;
          code: string;
        }[];
        const shelf = locations.find((row) => row.code === code);
        if (shelf) {
          const gone = await fetch(`${base}/stock-locations/${shelf.id}`, {
            method: 'DELETE',
            headers: { 'csrf-token': csrf },
          });
          if (!gone.ok) throw new Error(`deleting ${code} answered ${gone.status}`);
        }
      },
      [CSRF, shelfCode] as const,
    );
  }
});
