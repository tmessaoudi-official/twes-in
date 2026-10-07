// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test';
import { inACompany, signIn } from './session';

// docs/SPEC.md § 8 row 168: the icon font is cut to the icons src/app/shared/icons/icons.ts declares, so an icon it does
// not hold shows as its name in letters. The type and scripts/gates/icons-declared.sh refuse the names they can see;
// this catches one arriving from anywhere else, on the screens most icons are on. A drawn icon fits its box (the widest
// measured overflows by 4 px); a name left in letters overflows it by tens.

const CSRF = '0123456789abcdef0123456789abcdef';

test('every icon on the main screens is drawn, none left as its name in letters', async ({
  page,
}) => {
  test.setTimeout(90_000);
  await signIn(page);
  await inACompany(page, CSRF);
  for (const path of [
    '/',
    '/invoices',
    '/customers',
    '/products',
    '/delivery-notes',
    '/settings',
  ]) {
    await page.goto(path);
    await expect(page.locator('mat-icon').first()).toBeVisible();
    await page.evaluate(() => document.fonts.ready);
    const inLetters = await page.$$eval('mat-icon', (icons) =>
      icons
        .filter((icon) => icon instanceof HTMLElement && icon.offsetParent !== null)
        .filter((icon) => icon.scrollWidth - icon.clientWidth > 8)
        .map((icon) => icon.textContent?.trim()),
    );
    expect(inLetters, path).toEqual([]);
  }
});

test.describe('on a phone', () => {
  test.use({ viewport: { width: 390, height: 844 } });

  test('no icon is squeezed narrower than it is drawn beside a long text', async ({ page }) => {
    // Audit 2026-10-06 V-23 (c): a flex row of an icon and a long title shrank the icon to 21 px of its 24, which cut
    // the clock of « Clients en retard de paiement ».
    test.setTimeout(90_000);
    await signIn(page);
    await inACompany(page, CSRF);
    for (const path of ['/watch/invoices.late_customer', '/', '/invoices', '/account']) {
      await page.goto(path);
      await expect(page.locator('mat-icon:visible').first()).toBeVisible();
      await page.waitForLoadState('networkidle');
      await page.evaluate(() => document.fonts.ready);
      const squeezed = await page.$$eval('mat-icon', (icons) =>
        icons
          .filter((icon) => icon instanceof HTMLElement && icon.offsetParent !== null)
          .filter(
            (icon) =>
              icon.getBoundingClientRect().width + 1 < parseFloat(getComputedStyle(icon).fontSize),
          )
          .map((icon) => `${icon.textContent?.trim()} ${icon.getBoundingClientRect().width}`),
      );
      expect(squeezed, path).toEqual([]);
    }
  });
});
