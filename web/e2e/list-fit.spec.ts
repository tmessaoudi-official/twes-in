// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test';

import { inACompany, signIn } from './session';

// A list hides its lowest columns when they do not fit and names them in the chooser. On the widest page every
// column shown at first fits: a default column hidden there would read « masquée faute de place » on any screen, with
// nothing a person could do about it. A column that cannot fit is shown on request instead.
const CSRF = '0123456789abcdef0123456789abcdef';
const LISTS = [
  '/customers',
  '/products',
  '/invoices',
  '/invoices/recurring',
  '/quotes',
  '/delivery-notes',
  '/stock',
  '/stock/movements',
  '/stock/locations',
  '/vendors',
  '/purchases',
  '/expenses',
  '/expenses/recurring',
  '/price-lists',
];

test('every column a list shows at first fits the widest page', async ({ page }) => {
  // Fourteen lists opened one after the other, each with its chooser.
  test.setTimeout(120_000);
  // The page stops widening past this window: wider screens give the lists no more room.
  await page.setViewportSize({ width: 1600, height: 900 });
  await signIn(page);
  await inACompany(page, CSRF);
  for (const path of LISTS) {
    await page.goto(path);
    await page.getByTestId('list-columns').click();
    await expect(page.getByTestId('list-columns-reset'), path).toBeVisible();
    await expect(page.locator('[data-testid^="list-column-squeezed-"]'), path).toHaveCount(0);
    await page.keyboard.press('Escape');
  }
});
