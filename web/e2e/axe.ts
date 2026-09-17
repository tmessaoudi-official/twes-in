// SPDX-License-Identifier: AGPL-3.0-or-later

import AxeBuilder from '@axe-core/playwright';
import { expect, type Page } from '@playwright/test';

/**
 * The WCAG 2.1 A and AA violations on the page as it rests, each with the elements it names. A toast that has just
 * opened is waited for first: Material draws its content, close button included, under aria-hidden and moves it into
 * its live region 150 ms later, so axe reading that moment reports aria-hidden-focus on a state nobody can reach.
 */
export async function wcagViolations(page: Page): Promise<string[]> {
  await expect(page.locator('[aria-hidden="true"] [data-testid="toast"]')).toHaveCount(0);
  // A tooltip is waited for too: clicking a control parks the pointer on it, `appLabel` shows its tooltip, and axe
  // reads colours through the fade — reporting a contrast nobody ever sees, since at rest the surface is 12:1. The
  // fade is on an ancestor, so the surface's own opacity is already 1 while it runs: ask the animations, not the
  // computed style (2026-09-17, CI run 35272397610 on a commit that changed one Markdown file).
  await expect
    .poll(
      () =>
        page.evaluate(() =>
          [...document.querySelectorAll('.mat-mdc-tooltip-panel')]
            .flatMap((panel) => panel.getAnimations({ subtree: true }))
            .every((animation) => 'running' !== animation.playState),
        ),
      // Real fades take 150 ms; the margin is for a scan taken while one is deliberately slowed, and for a loaded CI
      // runner, which is where this was found in the first place.
      { timeout: 10_000 },
    )
    .toBe(true);
  const axe = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
    .analyze();
  return axe.violations.map(
    (violation) =>
      `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`,
  );
}
