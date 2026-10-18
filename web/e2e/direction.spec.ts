// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { inACompany, signIn } from './session';

const CSRF = '0123456789abcdef0123456789abcdef';

const leftOf = async (page: Page, testId: string): Promise<number> =>
  (await page.getByTestId(testId).first().boundingBox())?.x ?? Number.NaN;

const directed = async (page: Page, direction: 'ltr' | 'rtl'): Promise<void> => {
  await page.evaluate((value) => (document.documentElement.dir = value), direction);
};

// One set of screens reads either way: the document's direction alone moves the menu to the line's start and runs a
// list's columns from the right. Arabic's texts are not shipped yet, so the direction is set on the document, the way
// the language facade sets it.
test('a screen turns over with the direction of its language', async ({ page }, testInfo) => {
  await signIn(page);
  await inACompany(page, CSRF);
  await page.goto('/customers');
  const table = page.getByTestId('customers-table');
  await expect(table).toBeVisible();
  await expect(page.locator('html')).toHaveAttribute('dir', 'ltr');

  expect(await leftOf(page, 'shell-nav')).toBeLessThan(await leftOf(page, 'customers-table'));
  await page.screenshot({ path: testInfo.outputPath('customers-ltr.png'), fullPage: false });

  await directed(page, 'rtl');
  await expect
    .poll(async () => (await leftOf(page, 'shell-nav')) - (await leftOf(page, 'customers-table')))
    .toBeGreaterThan(0);
  const cells = table.locator('tr').nth(1).locator('td');
  await expect
    .poll(
      async () =>
        ((await cells.last().boundingBox())?.x ?? 0) -
        ((await cells.first().boundingBox())?.x ?? 0),
    )
    .toBeLessThan(0);
  await page.screenshot({ path: testInfo.outputPath('customers-rtl.png'), fullPage: false });

  await directed(page, 'ltr');
});
