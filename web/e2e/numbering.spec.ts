// SPDX-License-Identifier: AGPL-3.0-or-later
import AxeBuilder from '@axe-core/playwright';
import { expect, type Page, test } from '@playwright/test';

// G3b through the real stack: the seeded Tunisian company starts with its default establishment, coded the way its
// preset says, and a numbering series per document type on it. The owner renumbers invoices, sees the next number
// before saving, and finds it after a reload. Invoices, because nothing numbers them before G7: once a document
// carries a number from a series, where it resumes no longer changes, and the delivery notes scenario numbers them.
// One database is shared by the whole suite, so the series is put back as it was found; no establishment is added,
// because an establishment cannot be removed.
const EMAIL = process.env['E2E_EMAIL'] ?? 'operator@twes.local';
const PASSWORD = process.env['E2E_PASSWORD'] ?? 'twes-operator-dev';
const CSRF = '0123456789abcdef0123456789abcdef';

interface Series {
  id: string;
  establishmentCode: string;
  documentType: string;
  format: string;
  nextNumber: number;
  resetPeriod: string;
}

async function signIn(page: Page): Promise<void> {
  await page.goto('/login');
  await page.getByTestId('email').fill(EMAIL);
  await page.getByTestId('password').fill(PASSWORD);
  await page.getByTestId('submit').click();
  await expect(page).toHaveURL(/\/$/);
}

async function wcagViolations(page: Page): Promise<string[]> {
  const axe = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
    .analyze();
  return axe.violations.map((violation) => violation.id);
}

async function invoices(page: Page): Promise<Series> {
  return page.evaluate(async () => {
    const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
    const rows = (await (
      await fetch(`/api/companies/${me.company.id}/numbering-series`)
    ).json()) as Series[];
    const found = rows.find((row) => row.documentType === 'invoice');
    if (!found) throw new Error('no invoice series');
    return found;
  });
}

async function putSeries(page: Page, series: Series): Promise<void> {
  const status = await page.evaluate(
    async ([csrf, row]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const response = await fetch(`/api/companies/${me.company.id}/numbering-series/${row.id}`, {
        method: 'PUT',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: JSON.stringify({
          format: row.format,
          nextNumber: row.nextNumber,
          resetPeriod: row.resetPeriod,
        }),
      });
      return response.status;
    },
    [CSRF, series] as const,
  );
  expect(status).toBe(200);
}

test('the owner renumbers invoices and sees the next number before saving', async ({ page }) => {
  await signIn(page);
  const original = await invoices(page);
  const code = original.establishmentCode;
  try {
    await page.goto('/company/establishments');
    await expect(page.getByTestId(`establishment-${code}`)).toBeVisible();
    expect(await wcagViolations(page)).toEqual([]);

    await page.goto('/company/numbering');
    await page.getByTestId(`series-edit-${code}-invoice`).click();
    await page.getByTestId('field-format').fill('FAC-{EST}-{YY}-{SEQ:4}');
    await page.getByTestId('field-nextNumber').fill('12');
    await expect(page.getByTestId('series-preview')).toContainText(
      new RegExp(`FAC-${code}-\\d{2}-0012`),
    );
    expect(await wcagViolations(page)).toEqual([]);

    await page.getByTestId('series-save').click();
    await expect(page.getByTestId('series-saved')).toBeVisible();

    await page.reload();
    await expect(page.getByTestId(`series-${code}-invoice`)).toContainText(
      new RegExp(`FAC-${code}-\\d{2}-0012`),
    );
  } finally {
    await putSeries(page, original);
  }
});
