// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';

import { sidewaysOverflow } from './overflow';
import { inACompany, signIn } from './session';

// WCAG 2.2 1.4.10 Reflow: at 320 CSS pixels wide nothing but a table or a code block scrolls sideways; 1.4.4 Resize
// Text: text at 200 % loses no content or function. One screen of each family: the home, a list, a record's form, a
// document being written, a settings page and the person's own account.
const CSRF = '0123456789abcdef0123456789abcdef';
const SCREENS = [
  '/',
  '/customers',
  '/products',
  '/invoices',
  '/stock',
  '/customers/new',
  '/invoices/new',
  '/company/profile',
  '/account',
] as const;

async function walk(page: Page): Promise<void> {
  for (const path of SCREENS) {
    await page.goto(path);
    const title = page.locator('h1').first();
    await expect(title, path).toBeVisible();
    // A title cut by the width, rather than wrapped, would be content lost.
    await expect
      .poll(() => title.evaluate((element) => element.scrollWidth - element.clientWidth), {
        message: path,
      })
      .toBeLessThanOrEqual(1);
    expect(await sidewaysOverflow(page), path).toBeLessThanOrEqual(0);
  }
}

test(
  'at 320 CSS pixels every screen family reflows: nothing scrolls sideways',
  { tag: '@phone' },
  async ({ page }) => {
    test.setTimeout(120_000);
    await page.setViewportSize({ width: 320, height: 640 });
    await signIn(page);
    await inACompany(page, CSRF);
    await expect(page.locator('html')).toHaveAttribute('data-window', 'compact');
    await walk(page);
  },
);

test('with the browser text at 200 % every screen family keeps its content within the width', async ({
  page,
  browserName,
}) => {
  // The browser's own default font size is set through the DevTools protocol, which only Chromium speaks.
  test.skip(browserName !== 'chromium', 'Page.setFontSizes is a Chromium DevTools command');
  test.setTimeout(120_000);
  await page.setViewportSize({ width: 1280, height: 800 });
  const devtools = await page.context().newCDPSession(page);
  await devtools.send('Page.setFontSizes', { fontSizes: { standard: 32, fixed: 26 } });
  await signIn(page);
  await inACompany(page, CSRF);
  await expect
    .poll(() => page.evaluate(() => getComputedStyle(document.documentElement).fontSize))
    .toBe('32px');
  // 1,280 pixels at twice the text are 640 effective ones: the rail, not the labelled menu.
  await expect(page.locator('html')).toHaveAttribute('data-window', 'medium');
  await walk(page);
});
