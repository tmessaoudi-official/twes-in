// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test';
import { wcagViolations } from './axe';
import { aProduct, forget } from './catalogue';
import { inACompany, signIn } from './session';

// docs/SPEC.md § 7, 2026-09-23 slice 5: one click hides, on this tab, what the company keeps from a customer looking
// at the screen; the operator, owner of Demo, may read costs, so the product form asks the cost until then.
const CSRF = '0123456789abcdef0123456789abcdef';

test('customer view hides the cost on the product form, says so, and gives it back', async ({
  page,
}) => {
  const run = Date.now().toString(36).toUpperCase();
  await signIn(page);
  await inACompany(page, CSRF);
  const ids: string[] = [];
  try {
    ids.push(await aProduct(page, `CV-${run}`));
    await page.goto(`/products/${ids[0]}`);
    await expect(page.getByTestId('field-costPrice')).toBeVisible();

    await page.getByTestId('customer-view-toggle').click();

    await expect(page.getByTestId('customer-view-banner')).toBeVisible();
    await expect(page.getByTestId('field-costPrice')).toHaveCount(0);
    await expect(page.getByTestId('field-unitPriceNet')).toBeVisible();
    expect(await wcagViolations(page)).toEqual([]);

    // The tab remembers it through a reload, as a counter left on customer view must stay on.
    await page.reload();
    await expect(page.getByTestId('customer-view-banner')).toBeVisible();
    await expect(page.getByTestId('field-unitPriceNet')).toBeVisible();
    await expect(page.getByTestId('field-costPrice')).toHaveCount(0);

    await page.getByTestId('customer-view-leave').click();
    await expect(page.getByTestId('customer-view-banner')).toHaveCount(0);
    await expect(page.getByTestId('field-costPrice')).toBeVisible();
  } finally {
    await forget(page, ids);
  }
});
