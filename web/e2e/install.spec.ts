// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test, type Page } from '@playwright/test';
import { wcagViolations } from './axe';
import { signIn } from './session';
import { toast } from './toast';

// Installing the application (docs/SPEC.md § 7, the install offer): Chrome's own check that it may be installed, the
// offer in the account menu opening the browser's dialog, and Safari's two steps on a phone. Edge on Windows and Safari
// on a real iPhone are not certified here; they are tried on the shop's machine (TODO.md).

/**
 * The manifest as Chrome itself read it from the page. Its installability check (`Page.getInstallabilityErrors`) is not
 * used: in Playwright's Chromium it answers nothing even for a manifest with no icons, so it could never fail.
 */
async function manifestRead(page: Page) {
  const cdp = await page.context().newCDPSession(page);
  const { url, errors, manifest } = (await cdp.send('Page.getAppManifest')) as {
    url: string;
    errors: { message: string }[];
    manifest?: {
      name?: string;
      display?: string;
      startUrl?: string;
      scope?: string;
      icons?: { url: string; sizes: string }[];
    };
  };
  return {
    url: new URL(url).pathname,
    errors: errors.map((error) => error.message),
    name: manifest?.name,
    display: manifest?.display,
    startUrl: manifest?.startUrl === undefined ? undefined : new URL(manifest.startUrl).pathname,
    scope: manifest?.scope === undefined ? undefined : new URL(manifest.scope).pathname,
    sizes: (manifest?.icons ?? []).map((icon) => icon.sizes),
  };
}

test('Chrome reads the manifest the API writes: a standalone application with its icons', async ({
  page,
  browserName,
}) => {
  test.skip(browserName !== 'chromium', 'read through Chrome’s own DevTools protocol');
  await page.goto('/login');
  await expect(page.locator('link[rel="manifest"]')).toHaveAttribute('href', '/api/web-manifest');

  await expect
    .poll(() => manifestRead(page))
    .toEqual({
      url: '/api/web-manifest',
      errors: [],
      name: 'twes-in',
      display: 'kStandalone',
      startUrl: '/',
      scope: '/',
      sizes: ['0x0', '192x192', '512x512', '0x0', '512x512'],
    });
});

test('the account menu opens the browser’s own dialog, and says when it is installed', async ({
  page,
}) => {
  await signIn(page);
  // The browser's offer, as Chrome hands it to the page, its dialog counted rather than drawn.
  await page.evaluate(() => {
    const offer = Object.assign(new Event('beforeinstallprompt', { cancelable: true }), {
      prompt: () => {
        (window as unknown as { prompted: number }).prompted += 1;
        return Promise.resolve();
      },
    });
    (window as unknown as { prompted: number }).prompted = 0;
    window.dispatchEvent(offer);
  });

  await page.getByTestId('user-menu').click();
  await page.getByTestId('install-app').click();
  await expect
    .poll(() => page.evaluate(() => (window as unknown as { prompted: number }).prompted))
    .toBe(1);

  await page.evaluate(() => window.dispatchEvent(new Event('appinstalled')));
  await expect(toast(page)).toContainText('twes-in est installé sur cet appareil.');
  await page.getByTestId('user-menu').click();
  await expect(page.getByTestId('account-page-link')).toBeVisible();
  await expect(page.getByTestId('install-app')).toHaveCount(0);
});

test(
  'on an iPhone, the home offers it once and Safari’s two steps are shown',
  { tag: '@phone' },
  async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.addInitScript(() => {
      // Safari on an iPhone, not yet installed: the one browser that tells a page so. Chrome's own offer, which a
      // Chromium run would receive, is kept from the page so the guide is what is tried.
      Object.defineProperty(navigator, 'standalone', { value: false, configurable: true });
      window.addEventListener(
        'beforeinstallprompt',
        (event) => event.stopImmediatePropagation(),
        true,
      );
    });
    await signIn(page);
    await page.evaluate(() => localStorage.removeItem('twes.install.later'));
    await page.reload();

    const card = page.getByTestId('home-install');
    await expect(card).toContainText('Installer twes-in sur ce téléphone');
    // Scanned before the guide opens: the dialog hides the page behind it from axe.
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('install-now').click();
    const guide = page.getByTestId('install-guide');
    await expect(guide).toContainText('Touchez « Partager »');
    await expect(guide).toContainText('Choisissez « Sur l’écran d’accueil », puis « Ajouter ».');
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('install-guide-close').click();
    await expect(guide).toHaveCount(0);

    await page.getByTestId('install-later').click();
    await expect(card).toHaveCount(0);
    await page.reload();
    await expect(page.getByTestId('bottom-bar')).toBeVisible();
    await expect(card).toHaveCount(0);
    await page.evaluate(() => localStorage.removeItem('twes.install.later'));
  },
);
