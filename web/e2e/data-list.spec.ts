// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { wcagViolations } from './axe';
import { aProduct, forget, stockKept } from './catalogue';
import { forgetPresentationChoices } from './presentation';
import { OPERATOR_EMAIL as EMAIL, inACompany, signIn as logIn } from './session';
import { sidewaysOverflow } from './overflow';
import { holds, pickOption } from './select';

// G2b and G3b: presentation preferences survive a reload, and a fresh browser, through the API's presentation
// chain, and the list chrome every screen shares stays accessible with its column chooser open, at desktop and
// phone width. The choices live in the shared database, so each scenario starts by forgetting the operator's own.

async function signIn(page: Page): Promise<void> {
  await logIn(page);
  await forgetPresentationChoices(page);
}

/** Whether a tooltip is still arriving: the fade is on an ancestor, so the surface's own opacity says nothing. */
async function tooltipsArriving(page: Page): Promise<boolean> {
  return page.evaluate(() =>
    [...document.querySelectorAll('.mat-mdc-tooltip-panel')]
      .flatMap((panel) => panel.getAnimations({ subtree: true }))
      .some((animation) => 'running' === animation.playState),
  );
}

async function expectAccessible(page: Page, screen: string): Promise<void> {
  expect(await wcagViolations(page), screen).toEqual([]);
}

async function openMembers(page: Page): Promise<void> {
  await page.goto('/members');
  await expect(page.getByTestId('members-title')).toBeVisible();
  await expect(page.getByTestId(`member-${EMAIL}`)).toBeVisible();
}

test('a column hidden from the chooser stays hidden after a reload, until the columns are reset', async ({
  page,
}) => {
  await signIn(page);
  await openMembers(page);
  await expect(page.getByTestId('list-header-email')).toBeVisible();

  await page.getByTestId('list-columns').click();
  await expectAccessible(page, 'members, column chooser open');
  await page.getByTestId('list-column-toggle-email').click();
  await expect(page.getByTestId('list-header-email')).toHaveCount(0);

  await page.reload();
  await expect(page.getByTestId(`member-${EMAIL}`)).toBeVisible();
  await expect(page.getByTestId('list-header-role')).toBeVisible();
  await expect(page.getByTestId('list-header-email')).toHaveCount(0);

  await page.getByTestId('list-columns').click();
  await page.getByTestId('list-columns-reset').click();
  await expect(page.getByTestId('list-header-email')).toBeVisible();
});

test('a scan waits for a tooltip to arrive instead of reading it mid-fade', async ({ page }) => {
  // CI read `color-contrast: .mat-mdc-tooltip-surface` on a commit that changed one Markdown file (run 35272397610):
  // clicking a control parks the pointer on it, `appLabel` shows its tooltip, and axe reads the colours through the
  // fade. At rest that tooltip is 12:1. The animation is slowed here so the moment is reproducible rather than a
  // matter of how loaded the runner is (2026-09-17).
  await signIn(page);
  await openMembers(page);
  const cdp = await page.context().newCDPSession(page);
  await cdp.send('Animation.enable');
  // Slow for the whole test, the settling scan included: put the speed back first and the fade finishes by itself,
  // which is a pass whether or not the helper waits — the mutant that drops the wait went green that way.
  await cdp.send('Animation.setPlaybackRate', { playbackRate: 0.02 });
  await page.getByTestId('list-columns').click();

  // The tooltip has to be arriving for any of this to mean something: a scan taken before it appears finds nothing
  // and would read as a pass. At this playback rate it cannot finish arriving on its own.
  await expect(page.locator('.mat-mdc-tooltip-panel')).toHaveCount(1);
  await expect.poll(() => tooltipsArriving(page)).toBe(true);

  // What the helper promises is that it scans the page at rest, and that is what is asserted: whether a fade of a
  // given moment reads as a contrast violation depends on the scheme and on how far the fade got, which is why an
  // earlier version of this guard asserting the violation itself passed here and failed on CI (run 35275018376).
  expect(await wcagViolations(page), 'members, chooser open, tooltip settled').toEqual([]);
  expect(await tooltipsArriving(page), 'the scan waited for the tooltip').toBe(false);
});

test('a column moves without dragging, and the order survives a reload', async ({ page }) => {
  await signIn(page);
  await openMembers(page);

  await page.getByTestId('list-columns').click();
  await page.getByTestId('list-column-up-role').click();
  const order = () =>
    page
      .locator('[data-testid^="list-header-"]')
      .evaluateAll((cells) => cells.map((cell) => cell.getAttribute('data-testid')));
  await expect.poll(order).toEqual(['list-header-name', 'list-header-role', 'list-header-email']);

  await page.reload();
  await expect(page.getByTestId(`member-${EMAIL}`)).toBeVisible();
  await expect.poll(order).toEqual(['list-header-name', 'list-header-role', 'list-header-email']);
});

test('the dark scheme survives a reload', async ({ page }) => {
  await signIn(page);
  // Signed in, the scheme is in the member's menu (docs/SPEC.md § 7, 2026-09-25 17:22).
  await page.getByTestId('user-menu').click();
  await page.getByTestId('account-scheme-dark').click();
  await expect(page.locator('html')).toHaveClass(/theme-dark/);

  await page.reload();
  await expect(page.getByTestId('greeting')).toBeVisible();
  await expect(page.locator('html')).toHaveClass(/theme-dark/);
});

test('a saved view brings back its filters and columns after a reload', async ({ page }) => {
  await signIn(page);
  await openMembers(page);

  await page.getByTestId('list-filter').fill('operator');
  await pickOption(page, 'list-facet-role', 'list-facet-role-owner');
  await page.getByTestId('list-columns').click();
  await page.getByTestId('list-column-toggle-email').click();
  await page.getByTestId('list-views').click();
  await page.getByTestId('list-view-name').fill('Owners');
  await page.getByTestId('list-view-save').click();
  const saved = page.locator('[data-testid^="list-view-apply-"]', { hasText: 'Owners' });
  await expect(saved).toHaveAttribute('aria-pressed', 'true');
  await expectAccessible(page, 'members, saved views open');

  await page.reload();
  await expect(page.getByTestId(`member-${EMAIL}`)).toBeVisible();
  await expect(page.getByTestId('list-filter')).toHaveValue('');
  await page.getByTestId('list-columns').click();
  await page.getByTestId('list-columns-reset').click();
  await expect(page.getByTestId('list-header-email')).toBeVisible();

  await page.getByTestId('list-views').click();
  await saved.click();
  await expect(page.getByTestId('list-header-email')).toHaveCount(0);
  await expect(page.getByTestId('list-filter')).toHaveValue('operator');
  expect(await holds(page, 'list-facet-role', 'list-facet-role-owner')).toBe(true);
  await expect(page.getByTestId(`member-${EMAIL}`)).toBeVisible();
});

test('compact density follows the person into a fresh browser', async ({ page, browser }) => {
  await signIn(page);
  await expect(page.locator('html')).not.toHaveClass(/density-compact/);
  await page.getByTestId('user-menu').click();
  const saved = page.waitForResponse(
    (response) =>
      response.url().includes('/settings/presentation.density') &&
      response.request().method() === 'PUT',
  );
  await page.getByTestId('density-toggle').click();
  expect((await saved).status()).toBe(200);
  await expect(page.locator('html')).toHaveClass(/density-compact/);

  // A second browser context shares no storage with the first: only the API can carry the choice over.
  const elsewhere = await browser.newContext({ baseURL: test.info().project.use.baseURL });
  try {
    const other = await elsewhere.newPage();
    await logIn(other);
    await expect(other.getByTestId('greeting')).toBeVisible();
    await expect(other.locator('html')).toHaveClass(/density-compact/);
  } finally {
    await elsewhere.close();
  }
});

test('at phone width the members list and its chooser are accessible and fit the screen', async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await signIn(page);
  await openMembers(page);

  await page.getByTestId('list-columns').click();
  await expect(page.getByTestId('list-column-toggle-role')).toBeVisible();
  await expectAccessible(page, 'members, phone, chooser open');
  expect(
    await sidewaysOverflow(page),
    'the page itself must not scroll sideways',
  ).toBeLessThanOrEqual(0);
});

// The « Filtres » button joined the toolbar of the invoices and delivery notes lists, and on a phone the search, it and
// the two tool buttons no longer fitted one line: the whole page panned sideways and « Vues » and « Colonnes » sat off
// screen (visual audit 2026-10-06, V-26). Every paged list is walked, one with a filter chosen so its count shows.
test('at phone width no paged list makes the page scroll sideways, a filter chosen or not', async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await logIn(page);
  await inACompany(page, '0123456789abcdef0123456789abcdef');
  for (const path of [
    '/invoices',
    '/invoices?status=draft,overdue',
    '/delivery-notes',
    '/instruments',
    '/customers',
    '/products',
    '/vendors',
    '/expenses',
    '/stock',
    '/stock/movements',
  ]) {
    await page.goto(path);
    await expect(page.getByTestId('list-columns')).toBeVisible();
    expect(await sidewaysOverflow(page), `${path} must not scroll sideways`).toBeLessThanOrEqual(0);
  }
});

// The stock list put its quantity after the lot and use-by columns, so on a laptop the pinned row actions covered it
// (« Qu » at 1440, nothing at 1280): the one figure the list exists for was the one not seen (visual audit, V-13).
test('on a laptop the stock list shows each row’s quantity clear of the row actions', async ({
  page,
}) => {
  await page.setViewportSize({ width: 1280, height: 800 });
  await logIn(page);
  await inACompany(page, '0123456789abcdef0123456789abcdef');
  // The seeded company holds no stock, so the run receives its own, and the list is searched for it.
  const reference = `E2E-QTY-${Date.now().toString(36).toUpperCase()}`;
  const productId = await aProduct(page, reference);
  try {
    await stockKept(page, productId, true);
    await page.evaluate(
      async ([csrf, id]) => {
        const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
        const base = `/api/companies/${me.company.id}`;
        const locations = (await (await fetch(`${base}/stock-locations`)).json()) as {
          id: string;
        }[];
        const received = await fetch(`${base}/stock-movements`, {
          method: 'POST',
          headers: { 'content-type': 'application/json', 'csrf-token': csrf },
          body: JSON.stringify({
            operation: 'receive',
            productId: id,
            locationId: locations[0]!.id,
            quantity: '12',
          }),
        });
        if (!received.ok) throw new Error(`receiving answered ${received.status}`);
      },
      ['0123456789abcdef0123456789abcdef', productId] as const,
    );
    await page.goto('/stock');
    await page.getByTestId('list-filter').fill(reference);
    const quantity = page.locator('td[data-column="quantity"]').first();
    await expect(quantity).toContainText('12');
    const [cell, actions] = await Promise.all([
      quantity.boundingBox(),
      page.locator('td.twes-row-actions').first().boundingBox(),
    ]);
    expect(cell).not.toBeNull();
    expect(actions).not.toBeNull();
    expect(
      cell!.x + cell!.width,
      'the quantity ends before the pinned actions begin',
    ).toBeLessThanOrEqual(actions!.x + 1);
  } finally {
    await forget(page, [productId]);
  }
});

// A list with no row actions pinned its LAST header to the table's right edge while that column's cells scrolled on,
// so « Statut » covered « Reste à payer » on « Factures » (2026-09-26). At 900 px the invoice columns' own widths
// exceed the page, so the table is wider than its container with or without rows.
test('a list wider than its page keeps each header over its own column', async ({ page }) => {
  await page.setViewportSize({ width: 900, height: 700 });
  await logIn(page);
  await inACompany(page, '0123456789abcdef0123456789abcdef');
  await page.goto('/invoices');
  const headers = page.locator('tr.mat-mdc-header-row th');
  await expect(headers.first()).toBeVisible();
  const boxes = await headers.evaluateAll((cells) =>
    cells.map((cell) => {
      const box = cell.getBoundingClientRect();
      return { left: Math.round(box.left), right: Math.round(box.right) };
    }),
  );
  const table = await page.locator('table.mat-mdc-table').evaluate((element) => ({
    table: element.scrollWidth,
    container: element.parentElement?.clientWidth ?? 0,
  }));
  expect(table.table, 'the table is wider than its container').toBeGreaterThan(table.container);
  for (let index = 1; index < boxes.length; index++) {
    expect(boxes[index]!.left, `header ${index} starts where header ${index - 1} ends`).toBe(
      boxes[index - 1]!.right,
    );
  }
});

test('a list says it is loading while its answer is on its way, and that it could not load when the read fails', async ({
  page,
}) => {
  await logIn(page);
  await inACompany(page, '0123456789abcdef0123456789abcdef');
  const customers = /\/api\/companies\/[^/]+\/customers(\?|$)/;

  let release: () => void = () => undefined;
  const held = new Promise<void>((resolve) => (release = resolve));
  await page.route(customers, async (route) => {
    await held;
    await route.continue();
  });
  // What the list's filters name answers while its page is held: the list still says it is loading.
  const context = page.waitForResponse((response) => response.url().includes('/customer-options'));
  await page.goto('/customers');
  await expect(page.getByTestId('list-loading').first()).toHaveText('Chargement…');
  await context;
  await expect(page.getByTestId('list-loading').first()).toHaveText('Chargement…');
  await expect(page.getByTestId('customers-empty')).toHaveCount(0);
  release();
  await expect(page.getByTestId('list-loading')).toHaveCount(0);

  await page.unroute(customers);
  await page.route(customers, (route) => route.fulfill({ status: 503, body: '' }));
  await page.reload();
  await expect(page.getByTestId('list-failed').first()).toBeVisible();
  await expect(page.getByTestId('customers-empty')).toHaveCount(0);
});
