// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { signIn } from './session';
import { toast } from './toast';
import { wcagViolations } from './axe';

// G3b through the real stack: the seeded Tunisian company starts with its default establishment, coded the way its
// preset says, and a numbering series per document type on it. The owner changes how invoices are numbered, sees the
// next number before saving, and finds it after a reload. Only the format changes: the invoices scenario numbers
// invoices, and once a document carries a number from a series, where it resumes no longer changes.
// One database is shared by the whole suite, so the series is put back as it was found; no establishment is added,
// because an establishment cannot be removed.
const CSRF = '0123456789abcdef0123456789abcdef';

interface Series {
  id: string;
  establishmentCode: string;
  documentType: string;
  format: string;
  nextNumber: number;
  resetPeriod: string;
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

test('the owner reformats invoices and sees the next number before saving', async ({ page }) => {
  await signIn(page);
  const original = await invoices(page);
  const code = original.establishmentCode;
  const next = String(original.nextNumber).padStart(4, '0');
  try {
    await page.goto('/company/establishments');
    await expect(page.getByTestId(`establishment-${code}`)).toBeVisible();
    expect(await wcagViolations(page)).toEqual([]);

    await page.goto('/company/numbering');
    await page.getByTestId(`series-edit-${code}-invoice`).click();
    await page.getByTestId('field-format').fill('FAC-{EST}-{YY}-{SEQ:4}');
    await expect(page.getByTestId('series-preview')).toContainText(
      new RegExp(`FAC-${code}-\\d{2}-${next}`),
    );
    expect(await wcagViolations(page)).toEqual([]);

    await page.getByTestId('series-save').click();
    await expect(toast(page)).toContainText('La numérotation a été enregistrée.');

    await page.reload();
    await expect(page.getByTestId(`series-${code}-invoice`)).toContainText(
      new RegExp(`FAC-${code}-\\d{2}-${next}`),
    );
  } finally {
    await putSeries(page, original);
  }
});
