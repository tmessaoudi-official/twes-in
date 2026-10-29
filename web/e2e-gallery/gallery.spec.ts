// SPDX-License-Identifier: AGPL-3.0-or-later
import { mkdirSync, writeFileSync } from 'node:fs';
import { expect, type Page, test } from '@playwright/test';
import { wcagViolations } from '../e2e/axe';
import { forgetPresentationChoices } from '../e2e/presentation';
import { CODES_OPERATOR_EMAIL, OPERATOR_PASSWORD, signInWithCode } from '../e2e/session';
import { auditScreen, type Finding } from './audit';
import { focusFacts, type FocusFacts } from './focus';

// Every screen as it is, for a design review (see playwright.gallery.config.ts): each route at desktop and phone
// width, in the light and the dark scheme. The scheme follows the device, as it does for anyone who never chose
// one, so the operator's own presentation choices are forgotten first. A record page opens the first row of its
// list; a screen that redirects is captured where it lands, and the manifest says so.
const OUT = process.env['GALLERY_DIR'] ?? '../var/claude/gallery';
// The company whose screens are captured: one of `make fixtures`, so every list and record has rows to show.
const COMPANY = process.env['GALLERY_COMPANY'] ?? 'Carthage Conseil';

interface Screen {
  key: string;
  group: string;
  path: string;
  /** For a record page: the list whose first row opens it. */
  firstRowOf?: string;
}

const SIGNED_OUT: Screen[] = [
  { key: 'login', group: 'Connexion', path: '/login' },
  { key: 'signup', group: 'Connexion', path: '/signup' },
  { key: 'forgot-password', group: 'Connexion', path: '/forgot-password' },
  { key: 'invitation-invalid', group: 'Connexion', path: '/invitations/not-a-token' },
  { key: 'legal-mentions', group: 'Connexion', path: '/legal/mentions' },
  // The phone's own page, opened without a pairing's key: what a phone shows when the code it read is not one.
  { key: 'pair', group: 'Connexion', path: '/pair' },
];

const SIGNED_IN: Screen[] = [
  { key: 'home', group: 'Accueil', path: '/' },
  { key: 'customers', group: 'Clients', path: '/customers' },
  {
    key: 'customer',
    group: 'Clients',
    path: '',
    firstRowOf: '/customers',
  },
  { key: 'customer-new', group: 'Clients', path: '/customers/new' },
  { key: 'customer-groups', group: 'Clients', path: '/customers/groups' },
  { key: 'customers-import', group: 'Clients', path: '/imports/customers' },
  { key: 'products', group: 'Produits', path: '/products' },
  {
    key: 'product',
    group: 'Produits',
    path: '',
    firstRowOf: '/products',
  },
  { key: 'product-new', group: 'Produits', path: '/products/new' },
  { key: 'product-categories', group: 'Produits', path: '/products/categories' },
  { key: 'invoices', group: 'Factures', path: '/invoices' },
  {
    key: 'invoice',
    group: 'Factures',
    path: '',
    firstRowOf: '/invoices',
  },
  { key: 'invoice-new', group: 'Factures', path: '/invoices/new' },
  { key: 'invoices-recurring', group: 'Factures', path: '/invoices/recurring' },
  { key: 'quotes', group: 'Devis', path: '/quotes' },
  {
    key: 'quote',
    group: 'Devis',
    path: '',
    firstRowOf: '/quotes',
  },
  { key: 'quote-new', group: 'Devis', path: '/quotes/new' },
  { key: 'delivery-notes', group: 'Bons de livraison', path: '/delivery-notes' },
  {
    key: 'delivery-note',
    group: 'Bons de livraison',
    path: '',
    firstRowOf: '/delivery-notes',
  },
  { key: 'delivery-note-new', group: 'Bons de livraison', path: '/delivery-notes/new' },
  { key: 'stock', group: 'Stock', path: '/stock' },
  { key: 'stock-movements', group: 'Stock', path: '/stock/movements' },
  { key: 'stock-locations', group: 'Stock', path: '/stock/locations' },
  { key: 'stock-count', group: 'Stock', path: '/stock/count' },
  { key: 'stock-valuation', group: 'Stock', path: '/stock/valuation' },
  { key: 'stock-plan', group: 'Stock', path: '/stock/plan' },
  { key: 'location-labels', group: 'Stock', path: '/print/location-labels' },
  { key: 'product-labels', group: 'Produits', path: '/print/product-labels' },
  { key: 'instruments', group: 'Factures', path: '/instruments' },
  { key: 'price-lists', group: 'Produits', path: '/price-lists' },
  { key: 'reports', group: 'Rapports', path: '/reports' },
  // A report reads this month against the one before, a figure and its previous value in each cell.
  { key: 'report-sales-by-customer', group: 'Rapports', path: '/reports/sales-by-customer' },
  { key: 'watch', group: 'À surveiller', path: '/watch' },
  { key: 'watch-late', group: 'À surveiller', path: '/watch/invoices.late_customer' },
  { key: 'account', group: 'Mon compte', path: '/account' },
  { key: 'coming', group: 'Bientôt', path: '/coming/works' },
  { key: 'vendors', group: 'Fournisseurs', path: '/vendors' },
  {
    key: 'vendor',
    group: 'Fournisseurs',
    path: '',
    firstRowOf: '/vendors',
  },
  { key: 'vendor-new', group: 'Fournisseurs', path: '/vendors/new' },
  { key: 'expenses', group: 'Dépenses', path: '/expenses' },
  {
    key: 'expense',
    group: 'Dépenses',
    path: '',
    firstRowOf: '/expenses',
  },
  { key: 'expense-new', group: 'Dépenses', path: '/expenses/new' },
  { key: 'expense-categories', group: 'Dépenses', path: '/expenses/categories' },
  { key: 'expenses-recurring', group: 'Dépenses', path: '/expenses/recurring' },
  // The window turned to the customer, outside the shell: what it shows before a sale scans anything.
  { key: 'customer-display', group: 'Factures', path: '/customer-display' },
  { key: 'accounting-export', group: 'Dépenses', path: '/accounting-export' },
  { key: 'platform', group: 'Plateforme', path: '/platform' },
  { key: 'platform-legal', group: 'Plateforme', path: '/platform/legal' },
  { key: 'settings', group: 'Paramètres', path: '/settings' },
  { key: 'company', group: 'Paramètres', path: '/company' },
  { key: 'company-profile', group: 'Paramètres', path: '/company/profile' },
  { key: 'company-security', group: 'Paramètres', path: '/company/security' },
  { key: 'company-establishments', group: 'Paramètres', path: '/company/establishments' },
  { key: 'company-numbering', group: 'Paramètres', path: '/company/numbering' },
  { key: 'company-custom-fields', group: 'Paramètres', path: '/company/custom-fields' },
  { key: 'company-modules', group: 'Paramètres', path: '/company/modules' },
  { key: 'company-subscription', group: 'Paramètres', path: '/company/subscription' },
  { key: 'members', group: 'Paramètres', path: '/members' },
  { key: 'company-roles', group: 'Paramètres', path: '/company/roles' },
  { key: 'company-activity', group: 'Paramètres', path: '/company/activity' },
  { key: 'company-closing', group: 'Paramètres', path: '/company/closing' },
  { key: 'company-documents', group: 'Paramètres', path: '/company/documents' },
  { key: 'company-data-erasure', group: 'Paramètres', path: '/company/data-erasure' },
  { key: 'company-support-access', group: 'Paramètres', path: '/company/support-access' },
  { key: 'company-coming', group: 'Paramètres', path: '/company/coming/texts' },
  { key: 'products-import', group: 'Produits', path: '/imports/products' },
  { key: 'vendors-import', group: 'Fournisseurs', path: '/imports/vendors' },
  { key: 'opening-stock-import', group: 'Stock', path: '/imports/opening-stock' },
  { key: 'subscription-locked', group: 'Abonnement', path: '/subscription' },
  { key: 'awaiting-approval', group: 'Abonnement', path: '/awaiting-approval' },
  { key: 'fiscal-taxes', group: 'Paramètres', path: '/fiscal/taxes' },
  { key: 'fiscal-units', group: 'Paramètres', path: '/fiscal/units' },
];

// One width per window class of the shell: labelled menu, rail, bottom bar. The dark scheme is a matter of colour, not
// of width, so the rail's width is pictured light only.
// `phone-360` is the narrowest phone the review judges every screen at; it is pictured only when asked for.
const ALL_VIEWPORTS = [
  { name: 'desktop', width: 1440, height: 900, schemes: ['light', 'dark'] },
  { name: 'tablet', width: 1024, height: 768, schemes: ['light'] },
  { name: 'phone', width: 390, height: 844, schemes: ['light', 'dark'] },
  { name: 'phone-360', width: 360, height: 800, schemes: ['light', 'dark'] },
] as const;
// `GALLERY_WIDTHS=desktop,phone-360` pictures those widths alone; the default is the three window classes.
const WIDTHS = (process.env['GALLERY_WIDTHS'] ?? 'desktop,tablet,phone')
  .split(',')
  .filter((name) => name !== '');
const VIEWPORTS = ALL_VIEWPORTS.filter((viewport) => WIDTHS.includes(viewport.name));
// Who is signed in: the operator, or one of the testers `make fixtures` puts in every demo company, one per built-in
// role, so a screen is pictured as that role meets it (refused, read-only, or whole).
const AS = process.env['GALLERY_AS'] ?? 'operator';
const TESTERS = ['owner', 'admin', 'member', 'clerk', 'accountant'];
// DemoCompanies::TESTER_PASSWORD in the API's fixtures: e2e imports nothing from the app.
const TESTER_PASSWORD = 'twes-role-test-2026';
// `fr`, `en` or `ar`; `rtl` draws the French texts right to left, to tell a layout fault from a wording one.
const LANGUAGE = process.env['GALLERY_LANG'] ?? 'fr';
const RIGHT_TO_LEFT = LANGUAGE === 'rtl' || LANGUAGE === 'ar';
// `GALLERY_SCHEMES=light` pictures the light scheme alone, where a pass is about who sees what rather than colour.
const SCHEMES = (process.env['GALLERY_SCHEMES'] ?? 'light,dark').split(',');
// `GALLERY_AXE=1` adds the WCAG 2.1 A and AA scan of every screen at rest to the manifest.
const AXE = process.env['GALLERY_AXE'] === '1';
// `GALLERY_ONLY=invoice,home` pictures those screens alone, to check one change without the whole run.
const ONLY = (process.env['GALLERY_ONLY'] ?? '').split(',').filter((key) => key !== '');
// `GALLERY_FOCUS=1` runs the focus pass instead of the pictures: where the focus is on every screen and step, written
// to `focus.json` (the review's lens 53, focus and typing flow). Desktop, light, no picture.
const FOCUS = process.env['GALLERY_FOCUS'] === '1';
// The tallest picture: a long list is pictured to this height, its foot left out.
const TALLEST = 6000;

interface Shot {
  key: string;
  group: string;
  path: string;
  landed: string;
  viewport: string;
  scheme: string;
  /** What was open when it was pictured: nothing (`rest`), a list's filters, or a row's « ⋮ » menu. */
  state: 'rest' | 'filters' | 'menu';
  file: string;
  /** What a measurement calls wrong on the screen, for the sweep; empty when nothing was. */
  findings: Finding[];
  /** The WCAG violations at rest, when `GALLERY_AXE=1`; a scan that could not run says why. */
  axe?: string[];
}
const shots: Shot[] = [];
const missed: string[] = [];

async function settle(page: Page): Promise<void> {
  // The realtime connection never idles; what matters is that the page's own requests have answered.
  await page.waitForLoadState('networkidle', { timeout: 10_000 }).catch(() => undefined);
  await page.evaluate(() => document.fonts.ready);
  // A record's page keeps its title hidden until the record has arrived (docs/SPEC.md § 7, 2026-09-19 21:55), and the
  // bar shows while anything is still loading: a page that never gets there is reported missed, not pictured half-drawn.
  await page.locator('h1[aria-hidden="true"]').waitFor({ state: 'detached', timeout: 15_000 });
  await page.getByTestId('activity-progress').waitFor({ state: 'detached', timeout: 15_000 });
  // The app drew something: a page that never started is missed, not pictured blank, which the waits above pass on.
  await page.locator('app-root :visible').first().waitFor({ timeout: 15_000 });
  // The activity bar and any toast would otherwise sit in the picture.
  await page.waitForTimeout(400);
}

async function open(page: Page, screen: Screen): Promise<boolean> {
  if (screen.firstRowOf === undefined) {
    await page.goto(screen.path);
    return true;
  }
  await page.goto(screen.firstRowOf);
  await settle(page);
  // A row IS a link on the column that names it (docs/SPEC.md § 7, 2026-09-19 23:16, row 71): there is no longer
  // an "Ouvrir" in its actions, and a list without a link has no record page to picture. Its address is followed
  // rather than clicked, and a link to a side sheet over the list (`?open=<id>`, the invoices on a desktop) is
  // followed to the record's own page.
  const link = page.locator('a[data-testid^="list-link-"]').first();
  if ((await link.count()) === 0) return false;
  const href = await link.getAttribute('href');
  if (href === null) return false;
  const target = new URL(href, page.url());
  const sheet = target.searchParams.get('open');
  await page.goto(sheet === null ? href : `${target.pathname}/${sheet}`);
  await page.waitForURL((url) => url.pathname !== screen.firstRowOf, { timeout: 15_000 });
  return true;
}

async function captureAll(page: Page, screens: Screen[]): Promise<void> {
  for (const viewport of VIEWPORTS) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    for (const scheme of viewport.schemes.filter((each) => SCHEMES.includes(each))) {
      await page.emulateMedia({ colorScheme: scheme });
      for (const screen of screens.filter((each) => ONLY.length === 0 || ONLY.includes(each.key))) {
        try {
          if (!(await open(page, screen))) {
            missed.push(`${screen.key} ${viewport.name} ${scheme}: no row to open`);
            continue;
          }
          await settle(page);
        } catch (error) {
          missed.push(`${screen.key} ${viewport.name} ${scheme}: ${String(error).split('\n')[0]}`);
          continue;
        }
        // A right-to-left run whose page came out left to right pictures nothing it was asked for: it stops there.
        if (RIGHT_TO_LEFT) await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
        const file = `${screen.key}--${viewport.name}--${scheme}.jpg`;
        // Measured at the window's own size: the picture below stretches the window to the page's height.
        const findings = await auditScreen(page);
        const axe = AXE ? await scan(page) : undefined;
        await pictureWhole(page, viewport, `${OUT}/${file}`);
        const shot = {
          key: screen.key,
          group: screen.group,
          path: screen.path || `${screen.firstRowOf}/…`,
          landed: new URL(page.url()).pathname,
          viewport: viewport.name,
          scheme,
        };
        shots.push({
          ...shot,
          state: 'rest',
          file,
          findings,
          ...(axe === undefined ? {} : { axe }),
        });
        // A list is also used open: its filters, and a row's « ⋮ ». Pictured light, where the layout is the question.
        if (scheme === 'light') {
          for (const state of await openStates(page, screen, viewport, shot)) shots.push(state);
        }
        // Written after every picture, so a run cut short still says what it measured.
        writeFileSync(
          `${OUT}/manifest.json`,
          JSON.stringify({ as: AS, language: LANGUAGE, company: COMPANY, shots, missed }, null, 2),
        );
      }
    }
  }
}

/**
 * A list's two states besides its resting one: the filter panel open, and the first row's « ⋮ » menu open. Each is
 * measured and pictured in the window as it is (a menu sits over the window, not the page), then closed again.
 */
async function openStates(
  page: Page,
  screen: Screen,
  viewport: (typeof VIEWPORTS)[number],
  shot: Omit<Shot, 'state' | 'file' | 'findings'>,
): Promise<Shot[]> {
  const pictured: Shot[] = [];
  const filters = page.getByTestId('list-filters').first();
  if (await filters.isVisible().catch(() => false)) {
    try {
      await filters.click();
      await page.waitForTimeout(400);
      const findings = await auditScreen(page);
      const file = `${screen.key}--${viewport.name}--light--filters.jpg`;
      await pictureWhole(page, viewport, `${OUT}/${file}`);
      pictured.push({ ...shot, state: 'filters', file, findings });
      await filters.click();
    } catch (error) {
      missed.push(`${screen.key} ${viewport.name} filters: ${String(error).split('\n')[0]}`);
    }
  }
  const more = page.locator('[data-testid^="row-more-"]').first();
  if (await more.isVisible().catch(() => false)) {
    try {
      await more.click();
      await page
        .locator('.mat-mdc-menu-panel')
        .first()
        .waitFor({ state: 'visible', timeout: 5_000 });
      await page.waitForTimeout(300);
      const findings = await auditScreen(page);
      const file = `${screen.key}--${viewport.name}--light--menu.jpg`;
      await page.screenshot({
        path: `${OUT}/${file}`,
        type: 'jpeg',
        quality: 78,
        animations: 'disabled',
      });
      pictured.push({ ...shot, state: 'menu', file, findings });
      await page.keyboard.press('Escape');
    } catch (error) {
      missed.push(`${screen.key} ${viewport.name} menu: ${String(error).split('\n')[0]}`);
    }
  }
  return pictured;
}

/**
 * Pictures the whole screen in a window as tall as its content. The signed-in page scrolls inside its panel, which a
 * full-page capture does not unroll, and draws the phone's fixed bottom bar where the first window put it, mid-picture
 * over the content (audit 2026-10-06 V-4); in a window as tall as the page, the bar and the cookie notice sit at its
 * foot as a person scrolling there sees them.
 */
async function pictureWhole(
  page: Page,
  viewport: (typeof VIEWPORTS)[number],
  path: string,
): Promise<void> {
  const height = await page.evaluate(() => {
    const panel = document.querySelector('mat-sidenav-content');
    const document_ = document.documentElement;
    const hidden = panel === null ? 0 : panel.scrollHeight - panel.clientHeight;
    return Math.max(document_.scrollHeight, window.innerHeight + hidden);
  });
  await page.setViewportSize({ width: viewport.width, height: Math.min(height, TALLEST) });
  await settle(page);
  await page.screenshot({ path, type: 'jpeg', quality: 78, animations: 'disabled' });
  await page.setViewportSize({ width: viewport.width, height: viewport.height });
}

/**
 * Moves this page's session to the named company, as the company switcher does. The session is the gallery's own:
 * moving the one the e2e setup saved would leave every later scenario acting for the wrong company.
 */
async function workIn(page: Page, name: string): Promise<void> {
  const outcome = await page.evaluate(async (wanted) => {
    const headers = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'csrf-token': crypto.randomUUID(),
    };
    const listed = await fetch('/api/me/companies', { headers });
    // An operator is answered the companies they belong to under `member`, a member a plain list.
    const answered = (await listed.json()) as
      | { member: { companyId: string; name: string | null }[] }
      | { companyId: string; name: string | null }[];
    const companies = Array.isArray(answered) ? answered : answered.member;
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

/** The scan, or why it could not run: a screen the scan cannot read is a finding too, never a silent gap. */
async function scan(page: Page): Promise<string[]> {
  try {
    return await wcagViolations(page);
  } catch (error) {
    return [`scan-failed: ${String(error).split('\n')[0]}`];
  }
}

async function signInAs(page: Page, who: string): Promise<void> {
  if (who === 'operator') {
    await signInWithCode(page);
    return;
  }
  await page.goto('/login');
  await page.getByTestId('email').fill(`${who}@twes.local`);
  await page.getByTestId('login-continue').click();
  await page.getByTestId('password').fill(TESTER_PASSWORD);
  await page.getByTestId('submit').click();
  await expect(page).toHaveURL(/\/$/);
}

/**
 * The French texts drawn right to left. The language facade sets the document's direction from the language as the
 * app starts, and the CDK follows the document's, so the document is held at `rtl` whenever anything sets it back. An
 * init script runs before the parser has made the `<html>` element, so the document itself is watched, which also sees
 * the element arrive.
 */
async function rightToLeft(page: Page): Promise<void> {
  await page.addInitScript(() => {
    const hold = (): void => {
      const root = document.documentElement;
      if (root !== null && root.dir !== 'rtl') root.dir = 'rtl';
    };
    new MutationObserver(hold).observe(document, {
      childList: true,
      subtree: true,
      attributes: true,
      attributeFilter: ['dir'],
    });
    hold();
  });
}

/**
 * The signed-out pages in a language, as the browser keeps the choice someone made before signing in: the language
 * setting under the anonymous key, read as the app starts.
 */
async function signedOutIn(page: Page, language: string): Promise<void> {
  await page.addInitScript((tag) => {
    localStorage.setItem('twes.settings.anonymous.presentation.language', JSON.stringify(tag));
  }, language);
}

test('every screen, desktop and phone, light and dark', async ({ browser, page }) => {
  test.skip(FOCUS, 'the focus pass runs instead');
  const unknown = [
    ...WIDTHS.filter((name) => !ALL_VIEWPORTS.some((viewport) => viewport.name === name)),
    ...(AS === 'operator' || TESTERS.includes(AS) ? [] : [AS]),
    ...(['fr', 'en', 'ar', 'rtl'].includes(LANGUAGE) ? [] : [LANGUAGE]),
  ];
  if (unknown.length > 0) throw new Error(`unknown gallery choices: ${unknown.join(', ')}`);
  mkdirSync(OUT, { recursive: true });
  page.setDefaultNavigationTimeout(20_000);
  const locale = LANGUAGE === 'en' ? 'en-GB' : LANGUAGE === 'ar' ? 'ar-TN' : 'fr-FR';

  // A context of its own rather than `browser.newPage()`, which the WCAG scan refuses to read.
  const signedOutContext = await browser.newContext({ locale, timezoneId: 'Africa/Tunis' });
  const signedOut = await signedOutContext.newPage();
  if (LANGUAGE === 'rtl') await rightToLeft(signedOut);
  if (LANGUAGE === 'ar') await signedOutIn(signedOut, LANGUAGE);
  await captureAll(signedOut, SIGNED_OUT);
  await signedOutContext.close();

  if (LANGUAGE === 'rtl') await rightToLeft(page);
  await signInAs(page, AS);
  await workIn(page, COMPANY);
  await forgetPresentationChoices(page);
  if (LANGUAGE === 'en' || LANGUAGE === 'ar') {
    // Chosen as a person does, on « Mon compte »; the next run's forgetting puts it back.
    await page.goto('/account?tab=preferences');
    // The page's own Select; `account-menu-language` is the shell's account menu, closed here.
    await page.getByTestId('account-language').click();
    await page.getByTestId(`account-language-option-${LANGUAGE}`).click();
    await expect(page.locator('html')).toHaveAttribute('lang', LANGUAGE);
  }
  await captureAll(page, SIGNED_IN);

  writeFileSync(
    `${OUT}/manifest.json`,
    JSON.stringify({ as: AS, language: LANGUAGE, company: COMPANY, shots, missed }, null, 2),
  );
});

/** A screen reached one way: loaded afresh, or from inside the app as people move between screens. */
interface Arrival {
  key: string;
  path: string;
  /** `load`; `menu`, the shell's entry clicked; `row`, a list's row link clicked; `router`, the address pushed. */
  arrival: string;
  landed: string;
  facts: FocusFacts;
}

/** A step within a screen: a form refused, a step shown in place, a dialog opened or closed. */
interface Moment {
  flow: string;
  moment: string;
  facts: FocusFacts;
}

/**
 * Two frames and a moment after the page settled: focus moved by a component's own code lands after its render, and a
 * Material dialog moves it only once its opening animation has ended.
 */
async function steady(page: Page): Promise<void> {
  await page.waitForFunction(() => document.querySelector('.mdc-dialog--opening') === null, null, {
    timeout: 5_000,
  });
  await page.evaluate(
    () =>
      new Promise<void>((done) => requestAnimationFrame(() => requestAnimationFrame(() => done()))),
  );
  await page.waitForTimeout(300);
}

/**
 * Reaches a screen from the home page as a person does: its menu entry when the shell shows one, else the router
 * following an address pushed into the history; a record page through its list's first row. An HTML `autofocus` is
 * honoured about once a document, so this arrival, not a fresh load, is what people meet.
 */
async function arriveInApp(page: Page, screen: Screen): Promise<string> {
  const target = screen.firstRowOf ?? screen.path;
  const entry = page.locator(`app-root a[href="${target}"]:visible`).first();
  let how = 'router';
  if ((await entry.count()) > 0) {
    await entry.click();
    how = 'menu';
  } else {
    await page.evaluate((path) => {
      history.pushState(null, '', path);
      dispatchEvent(new PopStateEvent('popstate', { state: null }));
    }, target);
  }
  await page.waitForURL((url) => url.pathname === target, { timeout: 15_000 });
  await settle(page);
  if (screen.firstRowOf !== undefined) {
    const row = page.locator('a[data-testid^="list-link-"]').first();
    await row.click();
    await page.waitForURL((url) => url.pathname !== target || url.search !== '', {
      timeout: 15_000,
    });
    await settle(page);
    how = `${how}+row`;
  }
  return how;
}

test('where the focus is on every screen and step', async ({ browser, page }) => {
  test.skip(!FOCUS, 'GALLERY_FOCUS=1 runs the focus pass');
  mkdirSync(OUT, { recursive: true });
  page.setDefaultNavigationTimeout(20_000);
  const arrivals: Arrival[] = [];
  const moments: Moment[] = [];
  // Every write a dialog probe let through, which must stay empty: the probes open and cancel, never confirm.
  const writes: string[] = [];
  const missedHere: string[] = [];
  const save = (): void =>
    writeFileSync(
      `${OUT}/focus.json`,
      JSON.stringify(
        { as: AS, company: COMPANY, arrivals, moments, writes, missed: missedHere },
        null,
        2,
      ),
    );
  const landed = (on: Page): string => {
    const url = new URL(on.url());
    return url.pathname + url.search;
  };

  // Signed out: each screen as it loads, then the sign-in's steps as the operator who signs in with a code.
  const context = await browser.newContext({ locale: 'fr-FR', timezoneId: 'Africa/Tunis' });
  const signedOut = await context.newPage();
  for (const screen of SIGNED_OUT) {
    await signedOut.goto(screen.path);
    await settle(signedOut);
    await steady(signedOut);
    arrivals.push({
      key: screen.key,
      path: screen.path,
      arrival: 'load',
      landed: landed(signedOut),
      facts: await focusFacts(signedOut),
    });
  }
  await signedOut.goto('/login');
  await settle(signedOut);
  // An empty form is refused in the browser: nothing is sent.
  await signedOut.getByTestId('login-continue').click();
  await steady(signedOut);
  moments.push({
    flow: 'sign-in',
    moment: 'empty form refused',
    facts: await focusFacts(signedOut),
  });
  await signedOut.getByTestId('email').fill(CODES_OPERATOR_EMAIL);
  await signedOut.getByTestId('login-continue').click();
  await signedOut.getByTestId('password').fill(OPERATOR_PASSWORD);
  await signedOut.getByTestId('submit').click();
  const code = signedOut.getByTestId('mfa-code');
  await expect(code).toBeVisible();
  await steady(signedOut);
  moments.push({ flow: 'sign-in', moment: 'code step shown', facts: await focusFacts(signedOut) });
  // The probe's own check, from the page's side: it sees a focused field, and sees it go. The code is never sent.
  await code.evaluate((input: HTMLElement) => input.focus());
  expect((await focusFacts(signedOut)).focused).toContain('mfa-code');
  await code.evaluate((input: HTMLElement) => input.blur());
  expect((await focusFacts(signedOut)).focused).toBe('body');
  await context.close();
  save();

  await signInAs(page, AS);
  await workIn(page, COMPANY);
  await forgetPresentationChoices(page);
  for (const screen of SIGNED_IN.filter((each) => ONLY.length === 0 || ONLY.includes(each.key))) {
    try {
      if (!(await open(page, screen))) {
        missedHere.push(`${screen.key}: no row to open`);
        continue;
      }
      await settle(page);
      await steady(page);
      const path = screen.path || `${screen.firstRowOf}/…`;
      arrivals.push({
        key: screen.key,
        path,
        arrival: 'load',
        landed: landed(page),
        facts: await focusFacts(page),
      });
      if (screen.path !== '/') {
        await page.goto('/');
        await settle(page);
        const how = await arriveInApp(page, screen);
        await steady(page);
        arrivals.push({
          key: screen.key,
          path,
          arrival: how,
          landed: landed(page),
          facts: await focusFacts(page),
        });
      }
    } catch (error) {
      missedHere.push(`${screen.key}: ${String(error).split('\n')[0]}`);
    }
    save();
  }

  // Two dialogs, opened and cancelled with every write held back: the step-up, which « Effacer des données » asks for
  // before anything is sent, and the typed confirmation of deleting one's account.
  await page.route('**/api/**', (route) => {
    if (route.request().method() === 'GET') return route.fallback();
    writes.push(`${route.request().method()} ${new URL(route.request().url()).pathname}`);
    return route.abort();
  });
  const dialogs = [
    {
      flow: 'step-up',
      path: '/company/data-erasure',
      opener: 'erasure-confirm',
      field: 'step-up-password',
      cancel: 'step-up-cancel',
    },
    {
      flow: 'account deletion',
      path: '/account?tab=security',
      opener: 'account-delete',
      field: 'confirm-typed',
      cancel: 'confirm-keep',
    },
  ];
  for (const dialog of dialogs) {
    try {
      await page.goto(dialog.path);
      await settle(page);
      await page.getByTestId(dialog.opener).click();
      await expect(page.getByTestId(dialog.field)).toBeVisible();
      await steady(page);
      moments.push({ flow: dialog.flow, moment: 'opened', facts: await focusFacts(page) });
      await page.getByTestId(dialog.cancel).click();
      await page.locator('mat-dialog-container').waitFor({ state: 'detached', timeout: 10_000 });
      await steady(page);
      moments.push({ flow: dialog.flow, moment: 'closed', facts: await focusFacts(page) });
    } catch (error) {
      missedHere.push(`${dialog.flow}: ${String(error).split('\n')[0]}`);
    }
    save();
  }
  await page.unroute('**/api/**');
  save();
  expect(writes).toEqual([]);
});
