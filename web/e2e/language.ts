// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page } from '@playwright/test';

/**
 * Chooses the interface language as a signed-in person does: the account menu's « Langue » opens the language
 * dropdown in a dialog, and a choice there applies at once. Waits for the document to speak it.
 */
export async function chooseLanguage(page: Page, tag: string): Promise<void> {
  await page.getByTestId('user-menu').click();
  await page.getByTestId('account-menu-language').click();
  const dropdown = page.getByTestId('language-dialog');
  // The dialog moves focus only once it has opened: a click before then would be followed by that move.
  await expect(dropdown).toBeFocused();
  await dropdown.click();
  await page.getByTestId(`language-dialog-${tag}`).click();
  await expect(page.locator('html')).toHaveAttribute('lang', tag);
}
