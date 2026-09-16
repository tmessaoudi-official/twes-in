// SPDX-License-Identifier: AGPL-3.0-or-later

import type { Locator, Page } from '@playwright/test';

/**
 * The toast a person hears: Material draws it in an aria-hidden holder first and then moves it into its live region,
 * so for a moment two copies exist and only this one is announced.
 */
export function toast(page: Page): Locator {
  return page.locator('[aria-live] [data-testid="toast"]');
}
