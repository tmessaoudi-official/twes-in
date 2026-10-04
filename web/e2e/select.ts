// SPDX-License-Identifier: AGPL-3.0-or-later

import type { Page } from '@playwright/test';

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
