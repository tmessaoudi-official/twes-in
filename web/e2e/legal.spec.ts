// SPDX-License-Identifier: AGPL-3.0-or-later
import { readFileSync } from 'node:fs';
import { expect, test } from '@playwright/test';
import { wcagViolations } from './axe';
import { NOTICE_CLOSED, signIn } from './session';

// docs/SPEC.md § 7, 2026-09-26 08:52 (row 147): the copyright and legal links close every page, signed out or in,
// inside the page so they scroll with it; each link opens its page, open to anyone.

test('the legal line closes the sign-in page and leads to each legal page', async ({ page }) => {
  await page.goto('/login');
  const line = page.getByTestId('legal-footer');
  await expect(line).toBeVisible();
  await expect(line.getByTestId('legal-copyright')).toContainText(`© ${new Date().getFullYear()}`);
  await expect(line.getByRole('link')).toHaveCount(10);

  await line.getByTestId('legal-link-mentions').click();
  await expect(page).toHaveURL(/\/legal\/mentions$/);
  await expect(page.getByTestId('legal-title')).toHaveText('Mentions légales');
  await expect(page.getByTestId('legal-draft')).toBeVisible();
  expect(await wcagViolations(page)).toEqual([]);

  // The licence leads to the source page, as the AGPL asks of a network service.
  await page.getByTestId('legal-licence').click();
  await expect(page).toHaveURL(/\/legal\/source$/);
  await expect(page.getByTestId('legal-title')).toHaveText('Code source et licences');
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

  test('says what is stored, leads to the Cookies page, and stays closed once closed', async ({
    page,
  }) => {
    await page.goto('/login');
    const notice = page.getByTestId('cookie-notice');
    await expect(notice).toBeVisible();
    // In the flow at the top: it covers nothing, the sign-in form stays where a person expects it.
    expect((await notice.boundingBox())!.y).toBeLessThan(5);
    expect(await wcagViolations(page)).toEqual([]);

    await notice.getByTestId('cookie-notice-more').click();
    await expect(page).toHaveURL(/\/legal\/cookies$/);
    await expect(page.getByTestId('stored-row').first()).toContainText('twes_session');

    await page.getByTestId('cookie-notice-close').click();
    await expect(notice).toHaveCount(0);
    await page.reload();
    await expect(page.getByTestId('legal-title')).toBeVisible();
    await expect(page.getByTestId('cookie-notice')).toHaveCount(0);
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
