// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test';
import { wcagViolations } from './axe';
import { aProduct, forget, gtin, withCodes } from './catalogue';
import { inACompany, signIn } from './session';

// docs/SPEC.md § 7, 2026-09-23 slice 6: the customer display, a second window of the same browser, shows the line the
// counter's scan went onto, priced taxes included, and waits again once the sale leaves the screen. It must be a page
// of the same context: BroadcastChannel never reaches another browser profile.
const CSRF = '0123456789abcdef0123456789abcdef';

test('the customer display shows what the counter scans onto a sale, and waits again once it leaves', async ({
  page,
}) => {
  const run = Date.now().toString(36).toUpperCase();
  const code = gtin(`202${String(Date.now() % 1_000_000_000).padStart(9, '0')}`);
  await signIn(page);
  await inACompany(page, CSRF);
  const ids: string[] = [];
  try {
    ids.push(await aProduct(page, `CD-${run}`));
    await withCodes(page, ids[0], [code]);

    const display = await page.context().newPage();
    await display.goto('/customer-display');
    await expect(display.getByTestId('customer-display-waiting')).toBeVisible();

    await page.goto(`/invoices/new?scan=${code}`);
    await expect(page.getByTestId('line-0-description')).toHaveValue(`Vis CD-${run}`);

    await expect(display.getByTestId('customer-display-name')).toHaveText(`Vis CD-${run}`);
    await expect(display.getByTestId('customer-display-line')).toContainText('1 ×');
    await expect(display.getByTestId('customer-display-line')).toContainText('TND');
    expect(await wcagViolations(display)).toEqual([]);

    // Leaving the sale inside the app empties the display…
    await page.getByTestId('nav-products').click();
    await expect(display.getByTestId('customer-display-waiting')).toBeVisible();

    // …and so does leaving the page itself, where no screen is destroyed.
    await page.goto(`/invoices/new?scan=${code}`);
    await expect(display.getByTestId('customer-display-name')).toHaveText(`Vis CD-${run}`);
    await page.goto('/products');
    await expect(display.getByTestId('customer-display-waiting')).toBeVisible();
    await display.close();
  } finally {
    await forget(page, ids);
  }
});
