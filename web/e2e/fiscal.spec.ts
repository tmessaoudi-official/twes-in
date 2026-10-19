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

/** The kilogram's names by language, read from the API. */
async function kilogramNames(page: Page): Promise<Record<string, string>> {
  return page.evaluate(async () => {
    const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
    const units = (await (await fetch(`/api/companies/${me.company.id}/units`)).json()) as {
      code: string;
      names: Record<string, string>;
    }[];
    return units.find((unit) => unit.code === 'KGM')?.names ?? {};
  });
}

async function nameKilogram(page: Page, field: string, name: string): Promise<void> {
  await rowAction(page, 'unit-KGM', 'edit').click();
  await page.getByTestId(field).fill(name);
  await page.getByTestId('unit-save').click();
  await expect(toast(page)).toContainText('L’unité a été enregistrée.');
}

test('a unit carries its name in each language documents print in, as a person writes it', async ({
  page,
}) => {
  await signIn(page);
  await page.goto('/fiscal/units');

  // The fields are drawn from the languages documents print in, one per language, after the name.
  await rowAction(page, 'unit-KGM', 'edit').click();
  const fields = page.locator('[data-testid^="field-name-"]');
  await expect(fields.first()).toBeVisible();
  const field = (await fields.last().getAttribute('data-testid')) ?? '';
  const language = field.replace('field-name-', '');
  const original = await fields.last().inputValue();
  await page.getByTestId('unit-cancel').click();

  const written = `Kilo ${Date.now()}`;
  await nameKilogram(page, field, written);
  try {
    expect((await kilogramNames(page))[language]).toBe(written);
  } finally {
    await nameKilogram(page, field, original);
  }
  expect((await kilogramNames(page))[language] ?? '').toBe(original);
});
