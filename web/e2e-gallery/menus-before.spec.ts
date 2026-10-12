// SPDX-License-Identifier: AGPL-3.0-or-later
import { mkdirSync, writeFileSync } from 'node:fs';
import { type Page, test } from '@playwright/test';
import { forgetPresentationChoices } from '../e2e/presentation';
import { signInWithCode } from '../e2e/session';

// Row 260's « before »: every menu and action surface as it is today, open, for the before/after review. Not part of
// CI. Run against `make up` then `make fixtures`:
// make web-tools CMD="npx playwright test -c playwright.gallery.config.ts menus-before"
const OUT = process.env['MENUS_DIR'] ?? '../var/claude/menus-before';
const COMPANY = process.env['GALLERY_COMPANY'] ?? 'Carthage Conseil';

interface Pictured {
  key: string;
  width: number;
  url: string;
  file: string;
}
const pictured: Pictured[] = [];
const missed: string[] = [];

async function settle(page: Page): Promise<void> {
  await page.waitForLoadState('networkidle', { timeout: 10_000 }).catch(() => undefined);
  await page.evaluate(() => document.fonts.ready);
  await page
    .locator('h1[aria-hidden="true"]')
    .waitFor({ state: 'detached', timeout: 15_000 })
    .catch(() => undefined);
  await page
    .getByTestId('activity-progress')
    .waitFor({ state: 'detached', timeout: 15_000 })
    .catch(() => undefined);
  await page.waitForTimeout(500);
}

async function picture(page: Page, key: string): Promise<void> {
  const file = `${key}.jpg`;
  await page.screenshot({
    path: `${OUT}/${file}`,
    type: 'jpeg',
    quality: 72,
    animations: 'disabled',
  });
  pictured.push({
    key,
    width: page.viewportSize()?.width ?? 0,
    url: new URL(page.url()).pathname + new URL(page.url()).search,
    file,
  });
  writeFileSync(`${OUT}/manifest.json`, JSON.stringify({ pictured, missed }, null, 2));
}

/** Runs one capture, noting it as missed rather than stopping the run. */
async function attempt(page: Page, key: string, work: () => Promise<void>): Promise<void> {
  try {
    await work();
  } catch (error) {
    const seen = await page
      .evaluate(() =>
        [
          ...document.querySelectorAll(
            '[data-testid="user-menu"],[data-testid="company-switcher"],.cdk-overlay-backdrop,mat-dialog-container',
          ),
        ]
          .map(
            (e) =>
              `${e.getAttribute('data-testid') ?? e.className}:${Math.round(e.getBoundingClientRect().width)}`,
          )
          .join(' '),
      )
      .catch(() => '?');
    missed.push(`${key}: ${String(error).split('\n')[0]} [${page.url()} | ${seen}]`);
    await page.keyboard.press('Escape').catch(() => undefined);
  }
}

async function openMenu(page: Page, trigger: string): Promise<void> {
  const button = page.locator(`${trigger}:visible`).first();
  // A sidebar foot's trigger can sit under the menu's edge fade: the keyboard reaches it where a click is refused.
  await button.click({ timeout: 3_000 }).catch(async () => {
    await button.focus();
    await page.keyboard.press('Enter');
  });
  await page.locator('.mat-mdc-menu-panel').first().waitFor({ state: 'visible', timeout: 5_000 });
  await page.waitForTimeout(350);
}

/**
 * Escape only while something is open: one more, with the focus back on a trigger inside the sidebar, closes the
 * desktop sidebar itself (`mat-sidenav` closes on Escape).
 */
async function closeOverlays(page: Page): Promise<void> {
  for (let i = 0; i < 3; i++) {
    if ((await page.locator('.cdk-overlay-pane:visible, mat-dialog-container').count()) === 0)
      break;
    await page.keyboard.press('Escape');
    await page.waitForTimeout(250);
  }
}

/** The first row of a list, followed to its own page (a link to the sheet over the list opens the record instead). */
async function firstRecord(page: Page, list: string): Promise<string | null> {
  await page.goto(list);
  await settle(page);
  const link = page.locator('a[data-testid^="list-link-"]').first();
  // A list draws its rows after it settles: wait for the first, and call it empty only once the wait runs out.
  if (
    !(await link.waitFor({ state: 'attached', timeout: 10_000 }).then(
      () => true,
      () => false,
    ))
  )
    return null;
  const href = await link.getAttribute('href');
  if (href === null) return null;
  const target = new URL(href, page.url());
  const sheet = target.searchParams.get('open');
  return sheet === null ? target.pathname : `${target.pathname}/${sheet}`;
}

async function record(page: Page, key: string, list: string, more = true): Promise<void> {
  await attempt(page, key, async () => {
    const path = await firstRecord(page, list);
    if (path === null) throw new Error(`no row in ${list}`);
    await page.goto(path);
    await settle(page);
    await picture(page, key);
    if (!more) return;
    const trigger = page
      .locator('[data-testid="document-more"]:visible, [data-testid="record-more"]:visible')
      .first();
    if ((await trigger.count()) === 0) return;
    await trigger.click();
    await page.locator('.mat-mdc-menu-panel').first().waitFor({ state: 'visible', timeout: 5_000 });
    await page.waitForTimeout(350);
    await picture(page, `${key}--more`);
    await closeOverlays(page);
  });
}

async function list(page: Page, key: string, path: string): Promise<void> {
  await attempt(page, key, async () => {
    await page.goto(path);
    await settle(page);
    await picture(page, key);
    const more = page.locator('[data-testid^="row-more-"]').first();
    if (await more.isVisible().catch(() => false)) {
      await more.click();
      await page
        .locator('.mat-mdc-menu-panel')
        .first()
        .waitFor({ state: 'visible', timeout: 5_000 });
      await page.waitForTimeout(350);
      await picture(page, `${key}--row-more`);
      await closeOverlays(page);
    }
  });
}

async function workIn(page: Page, name: string): Promise<void> {
  const outcome = await page.evaluate(async (wanted) => {
    const headers = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'csrf-token': crypto.randomUUID(),
    };
    const listed = await fetch('/api/me/companies', { headers });
    const companies = (await listed.json()) as { companyId: string; name: string | null }[];
    const company = companies.find((each) => each.name === wanted);
    if (company === undefined) return `no company named ${wanted}: run make fixtures`;
    const switched = await fetch('/api/me/company', {
      method: 'POST',
      headers,
      body: JSON.stringify({ companyId: company.companyId }),
    });
    return switched.ok ? 'ok' : `switching answered ${switched.status}`;
  }, name);
  if (outcome !== 'ok') throw new Error(outcome);
}

test('every menu and action surface, open, as it is today', async ({ page }) => {
  mkdirSync(OUT, { recursive: true });
  page.setDefaultNavigationTimeout(20_000);
  // A trigger missing or hidden is noted as missed, never waited on for the gallery's two hours.
  page.setDefaultTimeout(8_000);
  await page.emulateMedia({ colorScheme: 'light' });
  await page.setViewportSize({ width: 1440, height: 900 });
  await signInWithCode(page);
  await workIn(page, COMPANY);
  await forgetPresentationChoices(page);

  // A. The shell.
  await page.goto('/');
  await settle(page);
  // The cookie notice would sit over every page's foot.
  await page
    .getByTestId('cookie-notice-close')
    .click()
    .catch(() => undefined);
  await picture(page, 'a-shell-home');
  await attempt(page, 'a-create', async () => {
    await openMenu(page, '[data-testid="create-open"]');
    await picture(page, 'a-create');
    await closeOverlays(page);
  });
  await attempt(page, 'a-account', async () => {
    await openMenu(page, '[data-testid="user-menu"]');
    await picture(page, 'a-account');
    await closeOverlays(page);
  });
  await attempt(page, 'a-company', async () => {
    await openMenu(page, '[data-testid="company-switcher"]');
    await picture(page, 'a-company');
    await closeOverlays(page);
  });
  await attempt(page, 'a-palette', async () => {
    await page.locator('[data-testid="command-open"]:visible').first().click();
    await page.getByTestId('command-palette').waitFor({ state: 'visible', timeout: 5_000 });
    await page.waitForTimeout(350);
    await picture(page, 'a-palette');
    await closeOverlays(page);
  });
  await attempt(page, 'a-help', async () => {
    await page.locator('[data-testid="help-open"]:visible').first().click();
    await page.waitForTimeout(700);
    await picture(page, 'a-help');
    await closeOverlays(page);
  });
  await attempt(page, 'a-settings', async () => {
    await page.goto('/settings');
    await settle(page);
    await picture(page, 'a-settings');
  });

  // B. Record and document pages, at rest and with « ⋮ » open.
  await record(page, 'b-invoice-draft', '/invoices?status=draft');
  await record(page, 'b-invoice-issued', '/invoices?status=issued');
  await record(page, 'b-invoice-paid', '/invoices?status=paid');
  await attempt(page, 'b-invoice-sheet', async () => {
    await page.goto('/invoices?status=issued');
    await settle(page);
    const link = page.locator('a[data-testid^="list-link-"]').first();
    await link.click();
    await page.waitForTimeout(900);
    await settle(page);
    await picture(page, 'b-invoice-sheet');
    await closeOverlays(page);
  });
  await record(page, 'b-quote-draft', '/quotes?status=draft');
  await record(page, 'b-quote-sent', '/quotes?status=sent');
  await record(page, 'b-quote-accepted', '/quotes?status=accepted');
  await record(page, 'b-dn-draft', '/delivery-notes?status=draft');
  await record(page, 'b-dn-validated', '/delivery-notes?status=validated');
  await record(page, 'b-customer', '/customers');
  await record(page, 'b-product', '/products');
  await record(page, 'b-vendor', '/vendors');
  await record(page, 'b-expense-draft', '/expenses?status=draft', false);
  await record(page, 'b-expense-recorded', '/expenses?status=recorded', false);
  await attempt(page, 'b-confirm', async () => {
    const path = await firstRecord(page, '/invoices?status=draft');
    if (path === null) throw new Error('no draft');
    await page.goto(path);
    await settle(page);
    await page.locator('[data-testid="document-more"]:visible').first().click();
    await page.locator('.mat-mdc-menu-panel').first().waitFor({ state: 'visible', timeout: 5_000 });
    await page
      .locator('.mat-mdc-menu-panel [role="menuitem"]')
      .filter({ hasText: /Abandonner le brouillon|Annuler le brouillon/ })
      .click();
    await page.locator('mat-dialog-container').waitFor({ state: 'visible', timeout: 5_000 });
    await page.waitForTimeout(400);
    await picture(page, 'e-confirm');
    await closeOverlays(page);
  });

  // C. Lists, with a row's « ⋮ » open where one exists.
  for (const [key, path] of [
    ['c-invoices', '/invoices'],
    ['c-quotes', '/quotes'],
    ['c-delivery-notes', '/delivery-notes'],
    ['c-customers', '/customers'],
    ['c-products', '/products'],
    ['c-vendors', '/vendors'],
    ['c-expenses', '/expenses'],
    ['c-instruments', '/instruments'],
    ['c-stock', '/stock'],
    ['c-movements', '/stock/movements'],
    ['c-locations', '/stock/locations'],
    ['c-price-lists', '/price-lists'],
    ['c-members', '/members'],
  ] as const) {
    await list(page, key, path);
  }

  // The phone: the bottom bar, its « Plus », a document's bar and a list as cards.
  await page.setViewportSize({ width: 390, height: 844 });
  await attempt(page, 'p-home', async () => {
    await page.goto('/');
    await settle(page);
    await picture(page, 'p-home');
    await page.locator('[data-testid^="bottom-nav-"]:visible').last().click();
    await page.waitForTimeout(700);
    await picture(page, 'p-plus');
    await closeOverlays(page);
  });
  await record(page, 'p-invoice-issued', '/invoices?status=issued');
  await list(page, 'p-invoices', '/invoices');
  await list(page, 'p-products', '/products');

  writeFileSync(`${OUT}/manifest.json`, JSON.stringify({ pictured, missed }, null, 2));
});
