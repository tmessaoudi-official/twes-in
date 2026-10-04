// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, type Page } from '@playwright/test';

/**
 * Has a multiple Select choose an option, whatever it held before: opened, the option taken only when it is not
 * already chosen, closed. A customer's regime and a product's defaults tick some taxes before anyone touches the
 * line, so a bare click would switch one off (the checkbox this replaces had `check()`, which does nothing twice).
 */
export async function choose(
  page: Page,
  selectTestId: string,
  name: RegExp | string,
): Promise<void> {
  await page.getByTestId(selectTestId).click();
  const option = page.getByRole('option', { name });
  if ((await option.getAttribute('aria-selected')) !== 'true') {
    await option.click();
  }
  await page.keyboard.press('Escape');
}

/** Has a Select take the option a test id names: for a choice whose label a test should not depend on. */
export async function pickOption(
  page: Page,
  selectTestId: string,
  optionTestId: string,
): Promise<void> {
  await page.getByTestId(selectTestId).click();
  await page.getByTestId(optionTestId).click();
}

/** Whether a Select holds the option a test id names: opened, read, closed. */
export async function holds(
  page: Page,
  selectTestId: string,
  optionTestId: string,
): Promise<boolean> {
  await page.getByTestId(selectTestId).click();
  const chosen = (await page.getByTestId(optionTestId).getAttribute('aria-selected')) === 'true';
  await page.keyboard.press('Escape');
  return chosen;
}

/** Has a list facet's Select say how many rows an option would list: opened, the count read, closed. */
export async function expectFacetCount(
  page: Page,
  optionTestId: string,
  count: RegExp | string,
): Promise<void> {
  const filter = optionTestId.split('-')[2];
  await page.getByTestId(`list-facet-${filter}`).click();
  await expect(page.getByTestId(optionTestId).locator('[data-option-count]')).toHaveText(count);
  await page.keyboard.press('Escape');
}
