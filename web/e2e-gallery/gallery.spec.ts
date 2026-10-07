// SPDX-License-Identifier: AGPL-3.0-or-later
import { mkdirSync, writeFileSync } from 'node:fs';
import { type Page, test } from '@playwright/test';
import { forgetPresentationChoices } from '../e2e/presentation';
import { signInWithCode } from '../e2e/session';

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
  { key: 'location-labels', group: 'Stock', path: '/location-labels' },
  { key: 'instruments', group: 'Factures', path: '/instruments' },
  { key: 'price-lists', group: 'Produits', path: '/price-lists' },
  { key: 'watch', group: 'À surveiller', path: '/watch' },
  { key: 'watch-late', group: 'À surveiller', path: '/watch/invoices.late_customer' },
  { key: 'account', group: 'Mon compte', path: '/account' },
  { key: 'coming', group: 'Bientôt', path: '/coming/quotes' },
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
  { key: 'fiscal-taxes', group: 'Paramètres', path: '/fiscal/taxes' },
  { key: 'fiscal-units', group: 'Paramètres', path: '/fiscal/units' },
];

const VIEWPORTS = [
  { name: 'desktop', width: 1440, height: 900 },
  { name: 'phone', width: 390, height: 844 },
] as const;
const SCHEMES = ['light', 'dark'] as const;
// `GALLERY_ONLY=invoice,home` pictures those screens alone, to check one change without the whole run.
const ONLY = (process.env['GALLERY_ONLY'] ?? '').split(',').filter((key) => key !== '');
// The tallest picture: a long list is pictured to this height, its foot left out.
const TALLEST = 6000;

interface Shot {
  key: string;
  group: string;
  path: string;
  landed: string;
  viewport: string;
  scheme: string;
  file: string;
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
    for (const scheme of SCHEMES) {
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
        const file = `${screen.key}--${viewport.name}--${scheme}.jpg`;
        await pictureWhole(page, viewport, `${OUT}/${file}`);
        shots.push({
          key: screen.key,
          group: screen.group,
          path: screen.path || `${screen.firstRowOf}/…`,
          landed: new URL(page.url()).pathname,
          viewport: viewport.name,
          scheme,
          file,
        });
      }
    }
  }
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

test('every screen, desktop and phone, light and dark', async ({ browser, page }) => {
  mkdirSync(OUT, { recursive: true });
  page.setDefaultNavigationTimeout(20_000);

  const signedOut = await browser.newPage({ locale: 'fr-FR', timezoneId: 'Africa/Tunis' });
  await captureAll(signedOut, SIGNED_OUT);
  await signedOut.close();

  await signInWithCode(page);
  await workIn(page, COMPANY);
  await forgetPresentationChoices(page);
  await captureAll(page, SIGNED_IN);

  writeFileSync(`${OUT}/manifest.json`, JSON.stringify({ shots, missed }, null, 2));
});
