// SPDX-License-Identifier: AGPL-3.0-or-later

import AxeBuilder from '@axe-core/playwright';
import { expect, type Page } from '@playwright/test';
import { iconFontLoaded } from './icon-font';

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
  // computed style (2026-09-17, CI run 35272397610 on a commit that changed one Markdown file). The same holds for
  // every overlay, not tooltips alone: a dialog read while it fades in failed on its filter chips (2026-09-23, CI run
  // 35833959425, the notification centre). An endless animation (a spinner) never finishes, and is not a fade.
  // Still means still for a few frames, not at one look: a pointer left where a panel closed hovers what lies beneath
  // on the next frame, and a tooltip there starts its fade a moment later, after a single look found nothing moving
  // (CI run 39093920621, the build line's tooltip under a select's option). And a fade is not an overlay's alone: a
  // field's mat-error fades in over 300 ms in the page itself, and a scan read the barcode refusal's red at 59 % on its
  // way in, 3:1 where it rests above 6:1 (CI run 40796135343). So every finite animation of the document is waited for.
  await expect
    .poll(
      () =>
        page.evaluate(async () => {
          const moving = (): boolean =>
            document
              .getAnimations()
              .filter((animation) => animation.effect?.getTiming().iterations !== Infinity)
              .some((animation) => 'running' === animation.playState);
          for (let frame = 0; frame < 3; frame += 1) {
            if (moving()) return false;
            await new Promise<void>((next) => requestAnimationFrame(() => next()));
          }
          return !moving();
        }),
      // Real fades take 150 ms; the margin is for a scan taken while one is deliberately slowed, and for a loaded CI
      // runner, which is where this was found in the first place.
      { timeout: 10_000 },
    )
    .toBe(true);
  const axe = await (
    await axeAtRest(page)
  )
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
    .analyze();
  return axe.violations.map(
    (violation) =>
      `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`,
  );
}

/**
 * The one way a scan starts: axe once the icon font has arrived. axe decides whether a font draws icons from the first
 * three it measures and keeps that answer for the whole scan, so a scan begun while the font is still on its way reads
 * every icon button as its name in words and fails label-content-name-mismatch on all of them at once.
 */
export async function axeAtRest(page: Page): Promise<AxeBuilder> {
  await iconFontLoaded(page);
  return new AxeBuilder({ page });
}
