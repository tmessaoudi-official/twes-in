// SPDX-License-Identifier: AGPL-3.0-or-later
import type { Page } from '@playwright/test';

/**
 * How far the page scrolls sideways, in pixels: 0 when it fits. The shell is as tall as the window and the page
 * scrolls inside its panel (docs/SPEC.md § 7, 2026-09-26 01:11), so a page too wide for the window widens the panel,
 * not the document: both are measured. A table or a code block scrolling in its own container is not counted.
 */
export async function sidewaysOverflow(page: Page): Promise<number> {
  return page.evaluate(() => {
    const document_ = document.documentElement;
    const panel = document.querySelector('mat-sidenav-content');
    return Math.max(
      document_.scrollWidth - document_.clientWidth,
      panel === null ? 0 : panel.scrollWidth - panel.clientWidth,
    );
  });
}
