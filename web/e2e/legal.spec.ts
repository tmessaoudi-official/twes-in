// SPDX-License-Identifier: AGPL-3.0-or-later
import { readFileSync } from 'node:fs';
import { expect, test } from '@playwright/test';
import { wcagViolations } from './axe';
import { NOTICE_CLOSED, signIn } from './session';

// The copyright and legal links close every page, signed out or in, centred, inside the page so they scroll with it;
// each link opens its text over the page, and each text is also a page of its own, open to anyone.

test('the legal line closes the sign-in page, centred, and opens each text over the page', async ({
  page,
}) => {
  await page.goto('/login');
  const line = page.getByTestId('legal-footer');
  await expect(line).toBeVisible();
  await expect(line.getByTestId('legal-copyright')).toContainText(`© ${new Date().getFullYear()}`);
  await expect(line.getByRole('link')).toHaveCount(10);
  // Centred under the page, whatever the page puts beside it (the API status on sign-in).
  const [middle, width] = await line.evaluate((nav) => {
    const boxes = [...nav.children].map((child) => child.getBoundingClientRect());
    const left = Math.min(...boxes.map((box) => box.left));
    const right = Math.max(...boxes.map((box) => box.right));
    return [(left + right) / 2, document.documentElement.clientWidth];
  });
  expect(Math.abs(middle - width / 2)).toBeLessThan(2);

  // A text opens over the page, which stays where it was; it is also a page of its own, to share or open in a tab.
  await page.getByTestId('email').fill('typed@before.reading');
  await line.getByTestId('legal-link-mentions').click();
  const panel = page.getByRole('dialog');
  await expect(panel.getByTestId('legal-title')).toHaveText('Mentions légales');
  await expect(page).toHaveURL(/\/login$/);
  expect(await wcagViolations(page)).toEqual([]);
  await panel.getByTestId('legal-close').click();
  await expect(panel).toHaveCount(0);
  await expect(page.getByTestId('email')).toHaveValue('typed@before.reading');
  await expect(line.getByTestId('legal-licence')).toHaveAttribute('href', '/legal/source');

  // Opened directly, the page's « Retour » leads home, which sends a signed-out visitor to sign in.
  await page.goto('/legal/mentions');
  await expect(page.getByTestId('legal-title')).toHaveText('Mentions légales');
  await expect(page.getByTestId('legal-draft')).toBeVisible();
  expect(await wcagViolations(page)).toEqual([]);
  await page.getByTestId('legal-back').click();
  await expect(page).toHaveURL(/\/login$/);
});

test('the legal line closes a signed-in page, after its content, and a settings page beside its list', async ({
  page,
}) => {
  await signIn(page);
  const line = page.locator('main').getByTestId('legal-footer');
  await expect(line).toBeVisible();
  // It is the page's last thing, below what the page shows, not a bar pinned over it. Both boxes are read in one
  // moment, once the home has drawn its panels: read apart, a page still growing moves one between the two reads.
  await expect(page.getByTestId('greeting')).toBeVisible();
  const [mainBottom, lineBottom, contentBottom] = await page.evaluate(() => {
    const main = document.querySelector('main')!;
    const line = main.querySelector('[data-testid="legal-footer"]')!;
    const others = [...main.children].filter((child) => child.tagName !== 'APP-LEGAL-FOOTER');
    return [
      main.getBoundingClientRect().bottom,
      line.getBoundingClientRect().bottom,
      Math.max(...others.map((child) => child.getBoundingClientRect().bottom)),
    ];
  });
  expect(lineBottom).toBeLessThanOrEqual(mainBottom + 1);
  expect(lineBottom).toBeGreaterThan(contentBottom);
  expect(await wcagViolations(page)).toEqual([]);

  await page.goto('/members');
  await expect(page.getByTestId('settings-page').getByTestId('legal-footer')).toBeVisible();
  await expect(page.getByTestId('legal-footer')).toHaveCount(1);
});

test.describe('a first visit', () => {
  // A truly fresh browser: nothing stored, the notice never closed (row 149).
  test.use({ storageState: { cookies: [], origins: [] } });

  test('says what is stored at the foot of the page, over nothing, and stays closed once closed', async ({
    page,
  }) => {
    await page.goto('/login');
    const notice = page.getByTestId('cookie-notice');
    await expect(notice).toBeVisible();
    // Held at the foot of the window while the page is read…
    const viewport = page.viewportSize()!;
    expect(
      Math.abs(
        (await notice.boundingBox())!.y + (await notice.boundingBox())!.height - viewport.height,
      ),
    ).toBeLessThan(2);
    // …and, scrolled to the end, below the page's last line rather than over it.
    await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
    const [lineBottom, noticeTop] = await page.evaluate(() => [
      document.querySelector('[data-testid="legal-footer"]')!.getBoundingClientRect().bottom,
      document.querySelector('[data-testid="cookie-notice"]')!.getBoundingClientRect().top,
    ]);
    expect(lineBottom).toBeLessThanOrEqual(noticeTop + 1);
    expect(await wcagViolations(page)).toEqual([]);

    // The app works while it is open: its link opens the Cookies text over the page.
    await notice.getByTestId('cookie-notice-more').click();
    const panel = page.getByRole('dialog');
    await expect(panel.getByTestId('stored-row').first()).toContainText('twes_session');
    await expect(page).toHaveURL(/\/login$/);
    await panel.getByTestId('legal-close').click();

    await page.getByTestId('cookie-notice-close').click();
    await expect(notice).toHaveCount(0);
    await page.reload();
    await expect(page.getByTestId('email')).toBeVisible();
    await expect(page.getByTestId('cookie-notice')).toHaveCount(0);
  });
});

test.describe('on a phone', () => {
  test.use({ viewport: { width: 390, height: 844 } });

  test('the notice stands on the bottom bar, never under it', async ({ page }) => {
    await signIn(page);
    await page.evaluate((key) => localStorage.removeItem(key), NOTICE_CLOSED);
    await page.reload();
    const notice = page.getByTestId('cookie-notice');
    await expect(notice).toBeVisible();
    await expect(page.getByTestId('bottom-bar')).toBeVisible();
    // The bar's height reaches the notice from a ResizeObserver, a frame after the bar is drawn.
    await expect
      .poll(() =>
        page.evaluate(() => {
          const notice = document.querySelector('[data-testid="cookie-notice"]')!;
          const bar = document.querySelector('[data-testid="bottom-bar"]')!;
          return Math.round(
            bar.getBoundingClientRect().top - notice.getBoundingClientRect().bottom,
          );
        }),
      )
      .toBe(0);
  });
});

test('stores in the browser nothing the Cookies page does not declare', async ({
  page,
  context,
}) => {
  // The declaration the gate checks the code against, read as the page reads it; this checks the running browser.
  const declared = [
    ...readFileSync('src/app/shared/legal/stored-items.ts', 'utf8').matchAll(/name: '([^']+)'/g),
  ].map(([, name]) => new RegExp(`^${name.replace(/\./g, '\\.').replace(/<[^>]+>/g, '.+')}$`));
  expect(declared.length).toBeGreaterThanOrEqual(6);

  await signIn(page);
  for (const path of ['/', '/invoices', '/members', '/account']) {
    await page.goto(path);
    await expect(page.locator('main')).toBeVisible();
  }
  const cookies = (await context.cookies()).map((cookie) => cookie.name);
  const keys = await page.evaluate(() => [
    ...Object.keys(localStorage),
    ...Object.keys(sessionStorage),
  ]);
  expect(cookies).toContain('twes_session');
  expect(keys).toContain(NOTICE_CLOSED);
  // Symfony's profiler (SecurityDataCollector) links a request to its authentication with these two, where it collects:
  // development only (docs/SPEC.md § 7, 2026-09-19), so production never sets them and the Cookies page does not list
  // them. Exempted by exact name, so any other cookie still reds here.
  const profiler = new Set(['main_auth_profile_token', 'main_deauth_profile_token']);
  const undeclared = [...cookies.filter((name) => !profiler.has(name)), ...keys].filter(
    (name) => !declared.some((rule) => rule.test(name)),
  );
  expect(undeclared).toEqual([]);
});
