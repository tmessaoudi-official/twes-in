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
  /** The test id prefix of that list's open link (`customer-open-<number>`). */
  openLink?: string;
}

const SIGNED_OUT: Screen[] = [
  { key: 'login', group: 'Connexion', path: '/login' },
  { key: 'signup', group: 'Connexion', path: '/signup' },
  { key: 'invitation-invalid', group: 'Connexion', path: '/invitations/not-a-token' },
];

const SIGNED_IN: Screen[] = [
  { key: 'home', group: 'Accueil', path: '/' },
  { key: 'customers', group: 'Clients', path: '/customers' },
  {
    key: 'customer',
    group: 'Clients',
    path: '',
    firstRowOf: '/customers',
    openLink: 'customer-open-',
  },
  { key: 'customer-new', group: 'Clients', path: '/customers/new' },
  { key: 'customer-groups', group: 'Clients', path: '/customers/groups' },
  { key: 'products', group: 'Produits', path: '/products' },
  {
    key: 'product',
    group: 'Produits',
    path: '',
    firstRowOf: '/products',
    openLink: 'product-open-',
  },
  { key: 'product-new', group: 'Produits', path: '/products/new' },
  { key: 'product-categories', group: 'Produits', path: '/products/categories' },
  { key: 'invoices', group: 'Factures', path: '/invoices' },
  {
    key: 'invoice',
    group: 'Factures',
    path: '',
    firstRowOf: '/invoices',
    openLink: 'invoice-open-',
  },
  { key: 'invoice-new', group: 'Factures', path: '/invoices/new' },
  { key: 'delivery-notes', group: 'Bons de livraison', path: '/delivery-notes' },
  {
    key: 'delivery-note',
    group: 'Bons de livraison',
    path: '',
    firstRowOf: '/delivery-notes',
    openLink: 'delivery-note-open-',
  },
  { key: 'delivery-note-new', group: 'Bons de livraison', path: '/delivery-notes/new' },
  { key: 'stock', group: 'Stock', path: '/stock' },
  { key: 'stock-movements', group: 'Stock', path: '/stock/movements' },
  { key: 'stock-locations', group: 'Stock', path: '/stock/locations' },
  { key: 'vendors', group: 'Fournisseurs', path: '/vendors' },
  {
    key: 'vendor',
    group: 'Fournisseurs',
    path: '',
    firstRowOf: '/vendors',
    openLink: 'vendor-open-',
  },
  { key: 'vendor-new', group: 'Fournisseurs', path: '/vendors/new' },
  { key: 'expenses', group: 'Dépenses', path: '/expenses' },
  {
    key: 'expense',
    group: 'Dépenses',
    path: '',
    firstRowOf: '/expenses',
    openLink: 'expense-open-',
  },
  { key: 'expense-new', group: 'Dépenses', path: '/expenses/new' },
  { key: 'expense-categories', group: 'Dépenses', path: '/expenses/categories' },
  { key: 'platform', group: 'Plateforme', path: '/platform' },
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
  { key: 'fiscal-taxes', group: 'Paramètres', path: '/fiscal/taxes' },
  { key: 'fiscal-units', group: 'Paramètres', path: '/fiscal/units' },
];

const VIEWPORTS = [
  { name: 'desktop', width: 1440, height: 900 },
  { name: 'phone', width: 390, height: 844 },
] as const;
const SCHEMES = ['light', 'dark'] as const;

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
  // A row opens its record through the link in its actions.
  const link = page.locator(`a[data-testid^="${screen.openLink}"]`).first();
  if ((await link.count()) === 0) return false;
  await link.click();
  await page.waitForURL((url) => url.pathname !== screen.firstRowOf, { timeout: 15_000 });
  return true;
}

async function captureAll(page: Page, screens: Screen[]): Promise<void> {
  for (const viewport of VIEWPORTS) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    for (const scheme of SCHEMES) {
      await page.emulateMedia({ colorScheme: scheme });
      for (const screen of screens) {
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
        if (viewport.name === 'phone') {
          // A full-page capture draws a fixed element where the viewport put it, so the phone's bottom bar would sit
          // mid-picture over whatever is there. Back in the flow, it closes the page, where scrolling meets it. Set
          // through the CSSOM: the page's Content-Security-Policy rightly refuses an injected <style>.
          await page.evaluate(() => {
            document
              .querySelector<HTMLElement>('.twes-bottom-bar')
              ?.style.setProperty('position', 'static', 'important');
          });
        }
        const file = `${screen.key}--${viewport.name}--${scheme}.jpg`;
        await page.screenshot({
          path: `${OUT}/${file}`,
          fullPage: true,
          type: 'jpeg',
          quality: 78,
          animations: 'disabled',
        });
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
