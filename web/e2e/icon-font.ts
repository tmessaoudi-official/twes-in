// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, type Page } from '@playwright/test';

/**
 * Resolves once the page draws its icons with the icon font. Before a scan: axe decides from the first fonts it
 * measures whether a font draws icons. Before leaving a page that has just opened: WebKit keeps a font load a
 * navigation cut short as failed, and the next page then draws every icon as its name in letters.
 */
export async function iconFontLoaded(page: Page): Promise<void> {
  await expect
    .poll(
      () =>
        page.evaluate(async () => {
          await document.fonts.load('24px "Material Symbols Outlined"');
          return document.fonts.check('24px "Material Symbols Outlined"');
        }),
      { timeout: 30_000 },
    )
    .toBe(true);
}
