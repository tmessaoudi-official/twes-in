// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';

import { forgetPresentationChoices } from './presentation';
import { inACompany, signIn } from './session';

// A list hides its lowest columns when they do not fit and names them in the chooser. On the widest page every
// column shown at first fits at its declared width: a default column hidden there would read « masquée faute de
// place » on any screen, whatever its values. A column that cannot fit is shown on request instead. The list is read
// with no row, so the declared widths alone are weighed; long values widening a column is the scenario below.
const CSRF = '0123456789abcdef0123456789abcdef';
/** A row holding a record: the line that says a list is loading or empty is a row of the table too. */
const RECORD_ROW = 'tr.mat-mdc-row:not(.mat-mdc-no-data-row)';
const LISTS = [
  '/customers',
  '/products',
  '/invoices',
  '/invoices/recurring',
  '/quotes',
  '/delivery-notes',
  '/stock',
  '/stock/movements',
  '/stock/locations',
  '/vendors',
  '/purchases',
  '/expenses',
  '/expenses/recurring',
  '/price-lists',
];

test('every column a list shows at first fits the widest page', async ({ page }) => {
  // Fourteen lists opened one after the other, each with its chooser.
  test.setTimeout(120_000);
  // The page stops widening past this window: wider screens give the lists no more room.
  await page.setViewportSize({ width: 1600, height: 900 });
  await signIn(page);
  await inACompany(page, CSRF);
  for (const path of LISTS) {
    // A list the API pages reads its words from the address and shows none; one that filters in the browser
    // (the locations) ignores them, and is weighed with its values.
    await page.goto(`${path}?q=qjxzvwqjxzvw`);
    await expect.poll(() => answered(page), { message: path }).toBe(true);
    await page.getByTestId('list-columns').click();
    await expect(page.getByTestId('list-columns-reset'), path).toBeVisible();
    await expect(page.locator('[data-testid^="list-column-squeezed-"]'), path).toHaveCount(0);
    await page.keyboard.press('Escape');
  }
});

/** Whether the list has answered: its rows, or the one line that says why there are none, once nothing is loading. */
function answered(page: Page): Promise<boolean> {
  return page.evaluate(
    (recordRow) =>
      document.querySelector(recordRow) !== null ||
      (document.querySelector('tr.mat-mdc-no-data-row') !== null &&
        document.querySelector('[data-testid="list-loading"]') === null),
    RECORD_ROW,
  );
}

/** The values a list's rows show cut short: the gallery audit's own test, a clipping box narrower than its text. */
async function cutValues(page: Page): Promise<{ rows: number; cut: string[] }> {
  return page.evaluate((recordRow) => {
    const rows = [...document.querySelectorAll(recordRow)];
    const cut = rows.flatMap((row) =>
      [...row.querySelectorAll('td *')]
        .filter((element) => {
          if (element.children.length > 0) return false;
          const style = getComputedStyle(element);
          if (style.overflowX !== 'hidden' && style.textOverflow !== 'ellipsis') return false;
          return element.scrollWidth > element.clientWidth + 1;
        })
        .map((element) => (element.textContent ?? '').trim()),
    );
    return { rows: rows.length, cut };
  }, RECORD_ROW);
}

/** Every list with rows, at one window width: none shows a value cut short once its columns have fitted. */
async function noValueCut(page: Page): Promise<number> {
  let read = 0;
  for (const path of LISTS) {
    await page.goto(path);
    await expect(page.getByTestId('list-columns'), path).toBeVisible();
    // An empty list has nothing to cut; the count below makes sure most lists were read with rows.
    const rows = page.locator(RECORD_ROW);
    await expect.poll(() => answered(page), { message: path }).toBe(true);
    if ((await rows.count()) === 0) continue;
    await expect.poll(async () => (await cutValues(page)).cut, { message: path }).toEqual([]);
    // Two frames later it still holds: the widths settled rather than passed through a moment that fits.
    await page.evaluate(
      () => new Promise((done) => requestAnimationFrame(() => requestAnimationFrame(done))),
    );
    expect((await cutValues(page)).cut, `${path}, settled`).toEqual([]);
    read += 1;
  }
  return read;
}

// A list never cuts a value: a column is as wide as its widest value, and where they do not all fit the lowest give
// way whole. Names in French and Arabic, at the widest page and at a laptop's.
test.describe('no list cuts a value', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page);
    await inACompany(page, CSRF);
    await forgetPresentationChoices(page);
  });
  test.afterEach(async ({ page }) => {
    await forgetPresentationChoices(page);
  });

  for (const language of ['fr', 'ar'] as const) {
    test(`in ${language}`, async ({ page }) => {
      test.setTimeout(240_000);
      if (language === 'ar') {
        await page.getByTestId('user-menu').click();
        await page.getByTestId('account-language-ar').click();
        await expect(page.locator('html')).toHaveAttribute('lang', 'ar');
      }
      for (const width of [1600, 1280]) {
        await page.setViewportSize({ width, height: 900 });
        expect(await noValueCut(page), `lists read with rows at ${width}`).toBeGreaterThanOrEqual(
          10,
        );
      }
    });
  }
});
