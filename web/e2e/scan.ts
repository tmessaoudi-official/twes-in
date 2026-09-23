// SPDX-License-Identifier: AGPL-3.0-or-later
import type { Page } from '@playwright/test';

/**
 * A handheld scanner's code, as the scanner types it: every character and the closing Enter in ONE burst. A separate
 * `keyboard.press('Enter')` is another round trip to the browser, and on a loaded CI runner that one arrived 24 ms
 * after the code on the driver's side alone — at the edge of the 30 ms the shell allows between a scan's keys — so the
 * burst read as typing and no card opened (CI run 35824358332). A scanner never pauses before its Enter.
 */
export async function scan(page: Page, code: string): Promise<void> {
  await page.keyboard.type(`${code}\n`);
}
