// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { wcagViolations } from './axe';
import { aCustomer } from './catalogue';
import { forgetPresentationChoices } from './presentation';
import { inACompany, signIn } from './session';
import { chooseLanguage } from './language';

const CSRF = '0123456789abcdef0123456789abcdef';

const leftOf = async (page: Page, testId: string): Promise<number> =>
  (await page.getByTestId(testId).first().boundingBox())?.x ?? Number.NaN;

// The person's own language is a presentation choice the API keeps, and the scenarios share the operator: each one
// here starts from the operator's defaults and goes back to them, passed or failed, so no later scenario reads Arabic.
test.beforeEach(async ({ page }) => {
  await signIn(page);
  await inACompany(page, CSRF);
  await forgetPresentationChoices(page);
});
test.afterEach(async ({ page }) => {
  await forgetPresentationChoices(page);
});

async function chooseArabic(page: Page): Promise<void> {
  await chooseLanguage(page, 'ar');
}

// One set of screens reads either way: Arabic, chosen as a person chooses it, turns the document over, moves the menu
// to the line's start, runs a list's columns from the right and speaks Arabic, and the same screen in French does
// none of it.
test('a screen in Arabic turns over and speaks Arabic', async ({ page }, testInfo) => {
  await page.goto('/customers');
  const table = page.getByTestId('customers-table');
  await expect(table).toBeVisible();
  await expect(page.locator('html')).toHaveAttribute('dir', 'ltr');
  expect(await leftOf(page, 'shell-nav')).toBeLessThan(await leftOf(page, 'customers-table'));
  await page.screenshot({ path: testInfo.outputPath('customers-ltr.png'), fullPage: false });

  await chooseArabic(page);
  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
  await expect(page.getByTestId('customers-title')).toHaveText('الحرفاء');
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
  expect(await wcagViolations(page), 'customers in Arabic').toEqual([]);
  await page.screenshot({ path: testInfo.outputPath('customers-rtl.png'), fullPage: false });

  // A dialog opens on the same side and reads the same way.
  await page.keyboard.press('Control+k');
  await expect(page.getByTestId('command-input')).toBeFocused();
  expect(
    await page
      .getByTestId('command-palette')
      .evaluate((palette) => getComputedStyle(palette).direction),
  ).toBe('rtl');
  expect(await wcagViolations(page), 'command palette in Arabic').toEqual([]);
  await page.keyboard.press('Escape');

  // Chosen again, a reload keeps it: the choice is the person's, kept by the API.
  await page.reload();
  await expect(page.locator('html')).toHaveAttribute('lang', 'ar');
  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
});

// A value cut short keeps its start in sight in either direction: its box cuts at the value's own end, so a Latin
// number in an Arabic list loses its last characters, never its first (« …791728493868 » was a customer number
// without its letters). A list sizes its columns to their values, so only a person cuts one: the number and name
// columns are narrowed with their handles, from the keyboard. A customer is never deleted, so each run leaves one, as
// the other scenarios creating customers do.
test('a value cut short in Arabic keeps its start in sight', async ({ page }, testInfo) => {
  const number = `RTL-${Date.now()}-QUINCAILLERIE`;
  await aCustomer(page, number);
  await chooseArabic(page);
  await page.goto('/customers');
  await page.getByTestId('list-filter').fill(number);
  const row = page.getByTestId('customers-table').locator('tr', { hasText: number });
  await expect(row).toHaveCount(1);
  await expect(row.locator('td .twes-value').first()).toBeVisible();
  // The arrow towards the line's start narrows a column: the right one in Arabic, 16 px a press.
  for (const column of ['number', 'name']) {
    await page.getByTestId(`list-resize-${column}`).focus();
    for (let press = 0; press < 10; press += 1) await page.keyboard.press('ArrowRight');
  }
  await page.screenshot({ path: testInfo.outputPath('customers-rtl-cut.png'), fullPage: false });

  const cut = await row.locator('td .twes-value').evaluateAll((boxes) =>
    boxes
      .filter((box) => box.scrollWidth > box.clientWidth)
      .map((box) => {
        const text = document.createTreeWalker(box, NodeFilter.SHOW_TEXT).nextNode() as Text;
        const start = text.data.search(/\S/);
        const range = document.createRange();
        range.setStart(text, start);
        range.setEnd(text, start + 1);
        const first = range.getBoundingClientRect();
        const shown = box.getBoundingClientRect();
        return {
          value: box.textContent?.trim(),
          firstInSight: first.left >= shown.left - 0.5 && first.right <= shown.right + 0.5,
        };
      }),
  );
  expect(
    cut.map((each) => each.value),
    'the number and the name are both cut',
  ).toEqual([number, `Client ${number}`]);
  expect(cut.filter((each) => !each.firstInSight)).toEqual([]);
});

// The screens a shop opens first, each without a WCAG failure in Arabic: a label that does not fit, a name not
// translated, an icon pointing the wrong way all show here before the shop sees them.
const ARABIC_WALK: readonly string[] = [
  '/',
  '/invoices',
  '/invoices/new',
  '/products',
  '/purchases',
  '/reports',
  '/settings',
];

test('the screens a shop opens first pass WCAG in Arabic', async ({ page }) => {
  await chooseArabic(page);
  let fieldsRead = 0;
  for (const route of ARABIC_WALK) {
    await page.goto(route);
    // A module switched off sends its route home, which would walk the home page again and read as covered.
    await expect(page).toHaveURL(
      (url) => url.pathname === route || url.pathname.startsWith(`${route}/`),
    );
    await expect(page.locator('#main-content h1').first()).toBeVisible();
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    expect(await wcagViolations(page), route).toEqual([]);
    // What is typed reads the way it is written: no field here reads in the screen's direction instead.
    const fields = await page
      .locator('input:not([type=checkbox]):not([type=radio]), textarea')
      .evaluateAll((all) => all.map((field) => getComputedStyle(field).unicodeBidi));
    expect(
      fields.filter((reading) => reading !== 'plaintext'),
      route,
    ).toEqual([]);
    fieldsRead += fields.length;
  }
  expect(fieldsRead, 'the fields are found, so an empty walk cannot pass').toBeGreaterThan(5);
});

// Arabic letters come from a face of their own behind the body's stack, fetched only once a page shows one: an Arabic
// customer's name on a French screen needs it as much as an Arabic screen does.
test('Arabic letters are drawn in the self-hosted Arabic face', async ({ page }, testInfo) => {
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
