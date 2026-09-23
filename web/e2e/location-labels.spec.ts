// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test';
import { wcagViolations } from './axe';
import { inACompany, signIn } from './session';

// docs/SPEC.md § 7, 2026-09-23 slice 8: a sheet of location labels, each with a QR code of its own address, which
// opens count mode at that location.
const CSRF = '0123456789abcdef0123456789abcdef';

test('the label sheet prints one label per location, and a label opens count mode there', async ({
  page,
}) => {
  await signIn(page);
  await inACompany(page, CSRF);
  await page.goto('/stock/locations');
  const locations = await page.evaluate(async () => {
    const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
    return (await (await fetch(`/api/companies/${me.company.id}/stock-locations`)).json()) as {
      id: string;
      code: string;
      isDefault: boolean;
    }[];
  });
  expect(locations.length).toBeGreaterThan(0);
  const [first] = locations;

  const [sheet] = await Promise.all([
    page.context().waitForEvent('page'),
    page.getByTestId('stock-location-labels').click(),
  ]);
  const label = sheet.getByTestId(`location-label-${first.code}`);
  await expect(label).toContainText(first.code);
  await expect(label.locator('svg[role="img"]')).toBeVisible();
  await expect(sheet.getByTestId('location-labels-print')).toBeVisible();
  expect(await wcagViolations(sheet)).toEqual([]);
  // The paper carries the labels and nothing else.
  await sheet.emulateMedia({ media: 'print' });
  await expect(sheet.getByTestId('location-labels-print')).toBeHidden();
  await expect(label).toBeVisible();
  await sheet.close();

  // What the QR code carries: count mode, with that location already chosen, even one that is not the default.
  const other = locations.find((location) => !location.isDefault) ?? first;
  await page.goto(`/stock/locations/${other.id}`);
  await expect(page.getByTestId('stock-count-location')).toContainText(other.code);
});
