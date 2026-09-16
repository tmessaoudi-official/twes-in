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
  const axe = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
    .analyze();
  return axe.violations.map(
    (violation) =>
      `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`,
  );
}
