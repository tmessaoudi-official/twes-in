// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Locator, type Page, test } from '@playwright/test';

import { aCustomer, aProduct, aVendor, forget, retireVendor } from './catalogue';
import { forgetPresentationChoices } from './presentation';
import { inACompany, signIn } from './session';
import { runDigits } from './run';
import { chooseLanguage } from './language';

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

// On a laptop (1280 px, the card 958 px) a list gives way only its columns of low priority: every other floor is what
// its header needs in any language, its format's widest value or a typed name's 160 px, which then wraps, so a generous
// floor never sends a column away. Read with no row, as above. The invoices keep their issue date only once a typed
// name may wrap narrower than it does today, the developer's call (pt-floor on the questions page), so their date is
// listed until then.
const GIVE_WAY_AT_1280: Record<string, readonly string[]> = {
  '/products': ['kind'],
  '/invoices': ['issueDate'],
  '/stock': ['useBy'],
};

test('on a laptop a list gives way only its columns of low priority', async ({ page }) => {
  test.setTimeout(120_000);
  await page.setViewportSize({ width: 1280, height: 900 });
  await signIn(page);
  await inACompany(page, CSRF);
  for (const language of ['fr', 'ar'] as const) {
    await chooseLanguage(page, language);
    for (const path of LISTS) {
      await page.goto(`${path}?q=qjxzvwqjxzvw`);
      await expect.poll(() => answered(page), { message: path }).toBe(true);
      await page.evaluate(() => document.fonts.ready);
      await page.getByTestId('list-columns').click();
      await expect(page.getByTestId('list-columns-reset'), path).toBeVisible();
      const squeezed = await page
        .locator('[data-testid^="list-column-squeezed-"]')
        .evaluateAll((entries) =>
          entries.map((entry) =>
            (entry.getAttribute('data-testid') ?? '').replace('list-column-squeezed-', ''),
          ),
        );
      await page.keyboard.press('Escape');
      expect
        .soft(squeezed.sort(), `${path} in ${language}`)
        .toEqual([...(GIVE_WAY_AT_1280[path] ?? [])].sort());
      // What stays is wide enough for its header, which a column narrower than its floor would cut.
      expect.soft((await cutValues(page)).cut, `${path} in ${language}, headers`).toEqual([]);
    }
  }
  await chooseLanguage(page, 'fr');
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

/**
 * The values a list's rows show cut short, by the gallery audit's own test (a clipping box narrower than its text), and
 * the headers whose label overruns their cell.
 */
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
    // A cell is cut as well when what it holds is wider than it, whatever box inside does the clipping.
    const cells = rows.flatMap((row) =>
      [...row.querySelectorAll<HTMLElement>('td[data-column]')]
        .filter((cell) => cell.scrollWidth > cell.clientWidth + 1)
        .map((cell) => `cell ${cell.dataset['column']}: ${(cell.textContent ?? '').trim()}`),
    );
    // A header is cut when its label draws past its cell's padding: it has no clipping box of its own.
    const headers = [...document.querySelectorAll<HTMLElement>('th[data-column]')].flatMap(
      (header) => {
        const label = header.querySelector('.mat-sort-header-content') ?? header;
        const range = document.createRange();
        range.selectNodeContents(label);
        const drawn = range.getBoundingClientRect();
        const box = header.getBoundingClientRect();
        const style = getComputedStyle(header);
        const inside =
          drawn.left >= box.left + parseFloat(style.paddingLeft) - 1 &&
          drawn.right <= box.right - parseFloat(style.paddingRight) + 1;
        return inside ? [] : [`header ${(label.textContent ?? '').trim()}`];
      },
    );
    return { rows: rows.length, cut: [...cut, ...cells, ...headers] };
  }, RECORD_ROW);
}

/** Every list with rows, at one window width: none shows a value cut short once its columns have fitted. */
async function noValueCut(page: Page, paths: readonly string[]): Promise<number> {
  let read = 0;
  for (const path of paths) {
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
  // The run's own records, each as long as its fields allow, so a code and a name wider than their columns declare are
  // read whatever else the company holds: CI's seed gives the operator a company and no row.
  const code = `F${Date.now().toString(36)}-QUINCAILLERIE-GENERALE`.slice(0, 32);
  let customer = '';
  let product = '';
  let vendor = '';
  test.beforeEach(async ({ page }) => {
    await signIn(page);
    await inACompany(page, CSRF);
    await forgetPresentationChoices(page);
    if (product === '') {
      customer = await aCustomer(page, code);
      product = await aProduct(page, code);
      vendor = await aVendor(page, code);
    }
  });
  test.afterEach(async ({ page }) => {
    await forgetPresentationChoices(page);
  });
  test.afterAll(async ({ browser }) => {
    if (product === '') return;
    const page = await browser.newPage();
    await signIn(page);
    await inACompany(page, CSRF);
    await forget(page, [product]);
    await retireVendor(page, vendor);
    await page.close();
  });

  for (const language of ['fr', 'ar'] as const) {
    test(`in ${language}`, async ({ page }) => {
      test.setTimeout(240_000);
      if (language === 'ar') {
        await chooseLanguage(page, 'ar');
      }
      for (const width of [1600, 1280]) {
        await page.setViewportSize({ width, height: 900 });
        // The run's records are found by their code, so these three lists are always read with rows.
        const own = ['/customers', '/products', '/vendors'].map((path) => `${path}?q=${code}`);
        expect(await noValueCut(page, own), `the run's records at ${width}`).toBe(own.length);
        // The rest are read where earlier scenarios or the demo left rows, which a fresh CI company may not have.
        await noValueCut(page, LISTS);
      }
    });
  }

  // The invoice's number cell is a link around a block; such a link's box is the cell's, which the table stretches to
  // fill the card, so measuring it widened the column by the room it was given, a little more at every measure.
  test('a column is as wide as its values, not as the room the table gave it', async ({ page }) => {
    const invoice = await aDraft(page, customer);
    try {
      await page.setViewportSize({ width: 1280, height: 900 });
      await page.goto(`/invoices?customer=${customer}`);
      await expect(page.locator(RECORD_ROW)).toHaveCount(1);
      const settled = async (): Promise<string> => {
        let before = '';
        await expect
          .poll(async () => {
            const now = await tableMinWidth(page);
            const same = now === before;
            before = now;
            return same;
          })
          .toBe(true);
        return before;
      };
      const narrow = await settled();
      await page.setViewportSize({ width: 1600, height: 900 });
      await settled();
      await page.setViewportSize({ width: 1280, height: 900 });
      expect(await settled()).toBe(narrow);
    } finally {
      await cancelDraft(page, invoice);
    }
  });

  // A customer's name a person typed may be longer than any laptop leaves it: it takes another line, and sends no column
  // away that a short name keeps, the status, the column the list is read for beside it, first among them.
  test('a long customer name takes another line and sends no column away', async ({ page }) => {
    const digits = runDigits();
    const longNamed = await aCustomerNamed(
      page,
      `L${digits}`,
      'Société générale de quincaillerie, matériaux de construction et outillage du Sahel',
    );
    const shortNamed = await aCustomerNamed(page, `S${digits}`, 'Sahel');
    const invoices = [await aDraft(page, longNamed), await aDraft(page, shortNamed)];
    try {
      await page.setViewportSize({ width: 1280, height: 900 });
      const squeezedFor = async (customerId: string): Promise<string[]> => {
        await page.goto(`/invoices?customer=${customerId}`);
        await expect(page.locator(RECORD_ROW)).toHaveCount(1);
        await page.evaluate(() => document.fonts.ready);
        return squeezedNow();
      };
      const squeezedNow = async (): Promise<string[]> => {
        await page.getByTestId('list-columns').click();
        await expect(page.getByTestId('list-columns-reset')).toBeVisible();
        const squeezed = await page
          .locator('[data-testid^="list-column-squeezed-"]')
          .evaluateAll((entries) =>
            entries.map((entry) => entry.getAttribute('data-testid') ?? ''),
          );
        await page.keyboard.press('Escape');
        return squeezed;
      };
      const besideAShortName = await squeezedFor(shortNamed);
      expect(besideAShortName).not.toContain('list-column-squeezed-status');
      expect(await squeezedFor(longNamed)).toEqual(besideAShortName);
      await expect(page.getByTestId('list-header-status')).toBeVisible();
      const name = page.locator(`${RECORD_ROW} td[data-column="customer"] .twes-value`);
      await expect.poll(() => drawnLines(name)).toBeGreaterThan(1);
      expect((await cutValues(page)).cut).toEqual([]);
      // A wrapping cell draws as wide as the room it is given, so measuring it would keep a wider window's room: the rows
      // read on a wide window, then a narrower one, the list sends away no column a fresh page at that width keeps. At
      // 1360 the card is narrower than at 1600 and no column has to give way, so a kept room would send one away.
      await page.setViewportSize({ width: 1360, height: 900 });
      const freshAt1360 = await squeezedFor(longNamed);
      await page.setViewportSize({ width: 1600, height: 900 });
      await page.reload();
      await expect(page.locator(RECORD_ROW)).toHaveCount(1);
      const tableWidth = (): Promise<number> =>
        page.evaluate(() => document.querySelector('table.mat-mdc-table')?.clientWidth ?? 0);
      await expect.poll(tableWidth).toBeGreaterThan(1050);
      await page.evaluate(
        () => new Promise((done) => requestAnimationFrame(() => requestAnimationFrame(done))),
      );
      await page.setViewportSize({ width: 1360, height: 900 });
      await expect.poll(() => scrollsSideways(page)).toBe(false);
      expect(await squeezedNow()).toEqual(freshAt1360);
      expect((await cutValues(page)).cut).toEqual([]);
    } finally {
      for (const invoice of invoices) await cancelDraft(page, invoice);
    }
  });

  // The status floor is its longest label in any language, so on a laptop the room it does not need goes to the
  // customer's name before the status gives way; a wider floor sent the status away for no label.
  test('the invoice status column is as wide as its longest label, and no wider', async ({
    page,
  }) => {
    const invoice = await aDraft(page, customer);
    try {
      // The widest page, where every column fits whatever this run's customer is called.
      await page.setViewportSize({ width: 1600, height: 900 });
      await page.goto(`/invoices?customer=${customer}`);
      // The draft, and the run's other drafts already cancelled, none of them the longest status.
      await expect(page.locator('td[data-column="status"] app-status-badge').first()).toBeVisible();
      await page.evaluate(() => document.fonts.ready);
      const longest = await longestStatusCell(page);
      await expect(page.getByTestId('list-resize-status')).toHaveAttribute(
        'aria-valuenow',
        String(longest),
      );
    } finally {
      await cancelDraft(page, invoice);
    }
  });
});

/**
 * How wide the invoice list's status cell draws its longest label, in any language the screens speak: the one
 * cell's badge, padding included, given each label in turn.
 */
async function longestStatusCell(page: Page): Promise<number> {
  return page.evaluate(async () => {
    const labels: string[] = [];
    for (const language of ['fr', 'en', 'ar']) {
      const texts = (await (await fetch(`/i18n/${language}.json`)).json()) as {
        invoices: { statuses: Record<string, string> };
      };
      labels.push(...Object.values(texts.invoices.statuses));
    }
    const cell = document.querySelector<HTMLElement>('td[data-column="status"]');
    const badge = cell?.querySelector<HTMLElement>('app-status-badge');
    // The label is the badge's own text, beside its dot.
    const label = [...(badge?.childNodes ?? [])].find(
      (node) => node.nodeType === Node.TEXT_NODE && (node.textContent ?? '').trim() !== '',
    );
    if (cell == null || badge == null || label === undefined) throw new Error('no status cell');
    const shown = label.textContent;
    const style = getComputedStyle(cell);
    const padding = parseFloat(style.paddingInlineStart) + parseFloat(style.paddingInlineEnd);
    let widest = 0;
    for (const text of labels) {
      label.textContent = text;
      widest = Math.max(widest, Math.ceil(badge.getBoundingClientRect().width + padding));
    }
    label.textContent = shown;
    return widest;
  });
}

// A column given way keeps the width it measured, so it does not come and go as the card moves; but that width is its
// rows': filtered down to rows it fits, it comes back.
test('a column given way for rows a filter removes comes back for the rows left', async ({
  page,
}) => {
  await signIn(page);
  await inACompany(page, CSRF);
  await forgetPresentationChoices(page);
  const run = `V${runDigits()}`;
  const wide = await aVendorIn(page, `${run}-W`, 'W'.repeat(120));
  const short = await aVendorIn(page, `${run}-S`, 'Sfax');
  try {
    await page.setViewportSize({ width: 1600, height: 900 });
    await page.goto(`/vendors?q=${run}`);
    await expect(page.locator(RECORD_ROW)).toHaveCount(2);
    // A city wider than the card leaves no room: its column gives way, and the chooser says so.
    await expect(page.locator('th[data-column="city"]')).toHaveCount(0);
    await page.getByTestId('list-filter').fill(`${run}-S`);
    await expect(page.locator(RECORD_ROW)).toHaveCount(1);
    await expect(page.locator('td[data-column="city"]')).toHaveText('Sfax');
  } finally {
    await retireVendor(page, wide);
    await retireVendor(page, short);
  }
});

/** A vendor in a city of the run's choosing, through the API; its id. */
async function aVendorIn(page: Page, number: string, city: string): Promise<string> {
  return page.evaluate(
    async ([csrf, vendorNumber, vendorCity]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const created = await fetch(`/api/companies/${me.company.id}/vendors`, {
        method: 'POST',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: JSON.stringify({
          number: vendorNumber,
          name: `Fournisseur ${vendorNumber}`,
          city: vendorCity,
        }),
      });
      if (!created.ok) throw new Error(`creating ${vendorNumber} answered ${created.status}`);
      return ((await created.json()) as { id: string }).id;
    },
    [CSRF, number, city] as const,
  );
}

function tableMinWidth(page: Page): Promise<string> {
  return page.evaluate(
    () =>
      new Promise<string>((done) =>
        requestAnimationFrame(() =>
          requestAnimationFrame(() => done(document.querySelector('table')?.style.minWidth ?? '')),
        ),
      ),
  );
}

/** A draft invoice for the run's customer, through the API; its id. */
async function aDraft(page: Page, customerId: string): Promise<string> {
  return page.evaluate(
    async ([csrf, id]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}`;
      const options = (await (await fetch(`${base}/invoice-options`)).json()) as {
        units: { id: string; code: string }[];
      };
      const unit = options.units.find((each) => each.code === 'C62') ?? options.units[0];
      const created = await fetch(`${base}/invoices`, {
        method: 'POST',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: JSON.stringify({
          customerId: id,
          establishmentId: null,
          lines: [
            {
              description: 'Vis à bois',
              quantity: '1',
              unitId: unit?.id,
              unitPriceNet: '1',
              discountRate: null,
              taxComponentIds: [],
            },
          ],
        }),
      });
      if (!created.ok) throw new Error(`creating the draft answered ${created.status}`);
      return ((await created.json()) as { id: string }).id;
    },
    [CSRF, customerId] as const,
  );
}

async function cancelDraft(page: Page, id: string): Promise<void> {
  const status = await page.evaluate(
    async ([csrf, invoice]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const cancelled = await fetch(`/api/companies/${me.company.id}/invoices/${invoice}/cancel`, {
        method: 'POST',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: 'null',
      });
      return cancelled.status;
    },
    [CSRF, id] as const,
  );
  expect(status).toBe(200);
}

/** A customer of the run's own, with the name given. */
async function aCustomerNamed(page: Page, number: string, name: string): Promise<string> {
  return page.evaluate(
    async ([csrf, customerNumber, customerName]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const created = await fetch(`/api/companies/${me.company.id}/customers`, {
        method: 'POST',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: JSON.stringify({
          number: customerNumber,
          kind: 'individual',
          taxRegime: 'standard',
          name: customerName,
        }),
      });
      if (!created.ok) throw new Error(`creating ${customerNumber} answered ${created.status}`);
      return ((await created.json()) as { id: string }).id;
    },
    [CSRF, number, name] as const,
  );
}

/** How many lines a box's text draws on, told by the tops of its line boxes. */
function drawnLines(box: Locator): Promise<number> {
  return box.evaluate((element) => {
    const range = document.createRange();
    range.selectNodeContents(element);
    return new Set([...range.getClientRects()].map((rect) => Math.round(rect.top))).size;
  });
}

/** Whether the list's table is wider than its card, so its columns run past the edge. */
function scrollsSideways(page: Page): Promise<boolean> {
  return page.evaluate(() => {
    const box = document.querySelector<HTMLElement>('.twes-list-table');
    return box === null ? true : box.scrollWidth > box.clientWidth + 1;
  });
}
