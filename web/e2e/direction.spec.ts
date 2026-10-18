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

// Arabic letters come from a face of their own behind the body's stack, fetched only once a page shows one: an Arabic
// customer's name on a French screen needs it as much as an Arabic screen does.
test('Arabic letters are drawn in the self-hosted Arabic face', async ({ page }, testInfo) => {
  await signIn(page);
  await inACompany(page, CSRF);
  await page.goto('/customers');
  await expect(page.getByTestId('customers-table')).toBeVisible();
  const families = await page.evaluate(async () => {
    const heading = document.createElement('p');
    heading.textContent = 'فاتورة رقم ١٢ — شركة النور';
    heading.style.fontSize = '2rem';
    (document.querySelector('main') ?? document.body).prepend(heading);
    const faces = await document.fonts.load(
      `32px ${getComputedStyle(document.body).fontFamily}`,
      heading.textContent,
    );
    return faces.filter((face) => face.status === 'loaded').map((face) => face.family);
  });
  expect(families.map((family) => family.replaceAll('"', ''))).toContain(
    'Noto Sans Arabic Variable',
  );
  await page.screenshot({ path: testInfo.outputPath('arabic-face.png'), fullPage: false });
});
