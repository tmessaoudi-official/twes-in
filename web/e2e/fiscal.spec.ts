// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, Page, test } from '@playwright/test';
import { signIn } from './session';
import { toast } from './toast';
import { rowAction } from './rows';

// G3a through the real bundle, nginx, FrankenPHP and PostgreSQL: the seeded company carries its Tunisian preset's
// taxes, units and customer regimes, and its owner revises one. The revision is undone at the end, because one
// database is shared by the whole suite.

/** The stamp's current name, read from the API so the test can put it back whatever an earlier run left. */
async function stampName(page: Page): Promise<string> {
  return page.evaluate(async () => {
    const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
    const taxes = (await (
      await fetch(`/api/companies/${me.company.id}/tax-components`)
    ).json()) as { code: string; name: string }[];
    return taxes.find((tax) => tax.code === 'TIMBRE')?.name ?? '';
  });
}

async function renameStamp(page: Page, name: string): Promise<void> {
  await rowAction(page, 'tax-TIMBRE', 'edit').click();
  await page.getByTestId('field-name').fill(name);
  await page.getByTestId('tax-save').click();
  await expect(toast(page)).toContainText('La taxe a été enregistrée.');
}

test('the seeded company lists its preset taxes and regimes, and its owner revises a tax', async ({
  page,
}) => {
  await signIn(page);
  await page.goto('/fiscal/taxes');

  await expect(page.getByTestId('tax-TVA19')).toBeVisible();
  await expect(page.getByTestId('tax-FODEC')).toBeVisible();
  await expect(page.getByTestId('regime-export')).toBeVisible();

  const original = await stampName(page);
  expect(original).not.toBe('');
  const renamed = `Timbre ${Date.now()}`;
  await renameStamp(page, renamed);
  try {
    await expect(page.getByTestId('tax-TIMBRE')).toContainText(renamed);
    // A stamp is an amount per document: its revision form offers an amount and no rate.
    await rowAction(page, 'tax-TIMBRE', 'edit').click();
    await expect(page.getByTestId('field-amount')).toBeVisible();
    await expect(page.getByTestId('field-rate')).toHaveCount(0);
    await page.getByTestId('tax-cancel').click();
  } finally {
    await renameStamp(page, original);
  }
});

test('the units page lists the preset units', async ({ page }) => {
  await signIn(page);
  await page.goto('/fiscal/units');

  await expect(page.getByTestId('unit-KGM')).toBeVisible();
  await expect(page.getByTestId('unit-HUR')).toBeVisible();
});
