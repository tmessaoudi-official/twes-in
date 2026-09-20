// SPDX-License-Identifier: AGPL-3.0-or-later

import type { Locator, Page } from '@playwright/test';

/**
 * A row's own action. The trailing cell keys each control on the row's identifier, which for a real record is a
 * uuid nothing here knows — so an action is found inside its row, by the test id the screen already names the row
 * with, rather than by an address a test would have to guess.
 */
export function rowAction(page: Page, row: string, action: string): Locator {
  return page.getByTestId(row).locator(`[data-testid^="row-action-${action}-"]`);
}

/** The "⋮" of a row, which holds what is rare or destructive. */
export function rowMore(page: Page, row: string): Locator {
  return page.getByTestId(row).locator('[data-testid^="row-more-"]');
}

/** An entry of that menu, which Material draws in an overlay off the body rather than inside the row. */
export function rowMenuItem(page: Page, action: string): Locator {
  return page.locator(`[data-testid^="row-menu-${action}-"]`);
}
