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
