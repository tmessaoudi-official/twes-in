// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, type Page, test } from '@playwright/test';
import { inACompany, signIn } from './session';
import { toast } from './toast';

/** The token every e2e sends with a write, as the others declare it. */
const CSRF = '0123456789abcdef0123456789abcdef';

/**
 * What changes globally changes what is on screen, without a refresh of the browser (developer, 2026-09-20).
 *
 * Both cases act on STORED preferences, so neither may assume a starting state: each brings the thing it tests to
 * a known value first and then changes it. A run that assumed one would pass or fail by what the run before left.
 */
async function forgetPreference(page: Page, key: string): Promise<void> {
  await page.evaluate(
    async ([setting, token]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } | null };
      if (me.company === null) throw new Error('the session is working in no company');
      const answered = await fetch(
        `/api/companies/${me.company.id}/settings/${encodeURIComponent(setting)}?level=user`,
        { method: 'DELETE', headers: { 'csrf-token': token } },
      );
      // 404 means it was never set, which is the state this asks for.
      if (!answered.ok && answered.status !== 404) {
        throw new Error(`forgetting ${setting} answered ${answered.status}`);
      }
    },
    [key, CSRF] as const,
  );
}

function primary(page: Page): Promise<string> {
  return page.evaluate(() =>
    getComputedStyle(document.documentElement).getPropertyValue('--mat-sys-primary').trim(),
  );
}

test('the menu folds and unfolds inside settings too, and each menu keeps its own answer', async ({
  page,
}) => {
  await signIn(page);
  await inACompany(page, CSRF);
  const nav = page.getByTestId('shell-nav');

  // Forgotten first, so both menus start at their DECLARED defaults whatever a previous run left. An earlier
  // version polled for "either valid value" and then compared, which is a check that cannot fail: it read the
  // general menu's state before the settings area had registered, and clicked the toggle the wrong way.
  await page.goto('/');
  await forgetPreference(page, 'presentation.sidebar');
  await forgetPreference(page, 'presentation.sidebar-settings');
  await page.reload();

  await expect(nav).toHaveAttribute('data-sidebar', 'expanded');

  // Settings opens labelled, as the round-6 board draws it (docs/SPEC.md § 7, 2026-09-25 17:22); folding it there
  // is remembered for settings alone.
  // The toggle folds the menu on screen: clicked while the settings route is still loading, it folded the general
  // one (CI, 2026-09-26: the trace's only write was to presentation.sidebar). Arrive first, then fold.
  await page.getByTestId('nav-settings').click();
  await expect(page.getByTestId('settings-area')).toBeVisible();
  await expect(nav).toHaveAttribute('data-sidebar', 'expanded');
  await page.getByTestId('sidebar-toggle').click();
  await expect(nav).toHaveAttribute('data-sidebar', 'rail');

  // The general menu is untouched by that, and folding IT does not unfold the settings one.
  await page.getByTestId('nav-home').click();
  await expect(page.getByTestId('settings-area')).toHaveCount(0);
  await expect(nav).toHaveAttribute('data-sidebar', 'expanded');
  await page.getByTestId('sidebar-toggle').click();
  await expect(nav).toHaveAttribute('data-sidebar', 'rail');
  await page.getByTestId('sidebar-toggle').click();
  await expect(nav).toHaveAttribute('data-sidebar', 'expanded');

  await page.getByTestId('nav-settings').click();
  await expect(page.getByTestId('settings-area')).toBeVisible();
  await expect(nav).toHaveAttribute('data-sidebar', 'rail');
  await forgetPreference(page, 'presentation.sidebar');
  await forgetPreference(page, 'presentation.sidebar-settings');
});

test("the company's default colour takes effect at once, with no refresh", async ({ page }) => {
  await signIn(page);
  await inACompany(page, CSRF);

  await page.goto('/settings');
  const accent = page.getByTestId('field-presentation__accent');
  await expect(accent).toBeVisible();

  // Whichever of the two it holds, save the other: filling the value it already has changes nothing, and the
  // case would then be measuring its own previous run.
  const wanted = (await accent.inputValue()) === '#b3261e' ? '#1f6feb' : '#b3261e';
  const before = await primary(page);

  await accent.fill(wanted);
  await page.getByTestId('settings-save').click();
  await expect(toast(page)).toBeVisible();

  // The page was never reloaded: the colour tokens on the document changed under it.
  await expect.poll(async () => primary(page)).not.toBe(before);
});
