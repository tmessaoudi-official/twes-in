// SPDX-License-Identifier: AGPL-3.0-or-later
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { expect, test, type Page } from '@playwright/test';
import { wcagViolations } from './axe';
import { aProduct, forget, gtin, withCodes } from './catalogue';
import { inACompany, signIn } from './session';

// docs/SPEC.md § 7, 2026-09-23 slice 8: printed product labels. Each label carries the product's name, reference,
// price and its code as a barcode a scanner reads back: EAN-13 for a GTIN, Code 128 for anything else. The proof
// is the decoder itself, the same zxing-cpp build the camera uses, run here in Node on a screenshot of the bars.
const CSRF = '0123456789abcdef0123456789abcdef';
const WASM = resolve(__dirname, '../node_modules/zxing-wasm/dist/reader/zxing_reader.wasm');

// A printer draws at 300 dpi, about three device pixels per CSS pixel: the bars are read at that resolution, as a
// label is, not at the screen's, where a module of a long Code 128 is narrower than a pixel.
test.use({ deviceScaleFactor: 3 });

async function decoded(page: Page): Promise<{ format: string; text: string }[]> {
  const bars = page.getByTestId('product-label').first().locator('svg[role="img"]');
  await expect(bars).toBeVisible();
  const zxing = await import('zxing-wasm/reader');
  zxing.prepareZXingModule({ overrides: { wasmBinary: readFileSync(WASM).buffer } });
  const symbols = await zxing.readBarcodes(new Blob([await bars.screenshot()]), {
    formats: ['EAN13', 'Code128'],
    tryHarder: true,
  });
  return symbols.map((symbol) => ({ format: symbol.format, text: symbol.text }));
}

test('a product label prints its code as a barcode a scanner reads back', async ({ page }) => {
  const run = Date.now().toString(36).toUpperCase();
  const reference = `LBL-${run}`;
  const code = gtin(`205${String(Date.now() % 1_000_000_000).padStart(9, '0')}`);
  await signIn(page);
  await inACompany(page, CSRF);
  const ids: string[] = [];
  try {
    ids.push(await aProduct(page, reference));
    await withCodes(page, ids[0], [code]);

    // The labels are an action of the saved product, opened in a tab of their own.
    await page.goto(`/products/${ids[0]}`);
    const [sheet] = await Promise.all([
      page.context().waitForEvent('page'),
      page.getByTestId('record-labels').click(),
    ]);
    const label = sheet.getByTestId('product-label');
    await expect(label).toHaveCount(1);
    await expect(label).toContainText(`Vis ${reference}`);
    await expect(label).toContainText(reference);
    await expect(label).toContainText(code);
    await expect(label.locator('svg[role="img"]')).toHaveAttribute('aria-label', code);
    expect(await wcagViolations(sheet)).toEqual([]);

    // As many copies as asked, and the paper carries the labels and nothing else.
    await sheet.getByTestId('product-labels-copies').fill('3');
    await expect(label).toHaveCount(3);
    await sheet.emulateMedia({ media: 'print' });
    await expect(sheet.getByTestId('product-labels-controls')).toBeHidden();
    await expect(label.first()).toBeVisible();
    await sheet.emulateMedia({ media: 'screen' });

    // A GTIN prints as EAN-13, and the decoder reads the code back from the bars.
    expect(await decoded(sheet)).toEqual([{ format: 'EAN13', text: code }]);
    await sheet.close();

    // Any other code prints as Code 128, read back the same way.
    const internal = `REF-${run}`;
    ids.push(await aProduct(page, `${reference}-B`));
    await withCodes(page, ids[1], [internal]);
    await page.goto(`/print/product-labels/${ids[1]}`);
    expect(await decoded(page)).toEqual([{ format: 'Code128', text: internal }]);
  } finally {
    await forget(page, ids);
  }
});
