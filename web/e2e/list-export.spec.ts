// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test';
import { inACompany, signIn } from './session';

// Row 60 through the real stack: every list that offers its rows as a file does so from the screen, and what the
// two links lead to is a real file, read with the session the browser holds (the API sets the cookie SameSite=Strict,
// so the file is fetched from inside the page, as a click on the link would). The first column of each file is the
// one its list names first, so a list that exports another list's rows reads as a failure.
const CSRF = '0123456789abcdef0123456789abcdef';

const LISTS = [
  { path: '/customers', testId: 'customers-export', firstColumn: 'number' },
  { path: '/invoices', testId: 'invoices-export', firstColumn: 'number' },
  { path: '/delivery-notes', testId: 'delivery-notes-export', firstColumn: 'number' },
  { path: '/products', testId: 'products-export', firstColumn: 'reference' },
  { path: '/vendors', testId: 'vendors-export', firstColumn: 'number' },
  { path: '/stock', testId: 'stock-export', firstColumn: 'product_reference' },
  { path: '/stock/movements', testId: 'stock-movements-export', firstColumn: 'date' },
  { path: '/expenses', testId: 'expenses-export', firstColumn: 'date' },
] as const;

test.describe('lists as files', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page);
    await inACompany(page, CSRF);
  });

  for (const list of LISTS) {
    test(`${list.path} offers a CSV and an Excel file that are what the list holds`, async ({
      page,
    }, testInfo) => {
      await page.goto(list.path);
      const csv = page.getByTestId(`${list.testId}-csv`);
      const xlsx = page.getByTestId(`${list.testId}-xlsx`);
      await expect(csv).toBeVisible();
      await expect(xlsx).toBeVisible();
      await page.screenshot({ path: testInfo.outputPath(`${list.testId}.png`) });

      const files = await page.evaluate(
        async ([csvAddress, xlsxAddress]) => {
          const read = async (address: string) => {
            const response = await fetch(address);
            const bytes = new Uint8Array(await response.arrayBuffer());
            return {
              status: response.status,
              type: response.headers.get('content-type') ?? '',
              start: new TextDecoder().decode(bytes.slice(0, 120)),
              zipped: bytes[0] === 0x50 && bytes[1] === 0x4b,
            };
          };
          return { csv: await read(csvAddress), xlsx: await read(xlsxAddress) };
        },
        [(await csv.getAttribute('href')) ?? '', (await xlsx.getAttribute('href')) ?? ''] as const,
      );

      expect(files.csv.status).toBe(200);
      expect(files.csv.type).toContain('text/csv');
      // A byte order mark leads the file so that Excel reads it as UTF-8.
      expect(files.csv.start.replace(/^﻿/, '').startsWith(list.firstColumn)).toBe(true);
      expect(files.xlsx.status).toBe(200);
      expect(files.xlsx.zipped).toBe(true);
    });
  }
});
