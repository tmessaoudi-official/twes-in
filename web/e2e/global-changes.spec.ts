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
async function foldTo(page: Page, wanted: 'expanded' | 'rail'): Promise<void> {
  const nav = page.getByTestId('shell-nav');
  // The stored answer arrives with the settings chain, after the first paint: poll rather than read once.
  await expect.poll(async () => nav.getAttribute('data-sidebar')).toMatch(/^(expanded|rail)$/);
  if ((await nav.getAttribute('data-sidebar')) !== wanted) {
    await page.getByTestId('sidebar-toggle').click();
  }
  await expect(nav).toHaveAttribute('data-sidebar', wanted);
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

  // The general menu unfolded and the settings menu folded: the state each opens in today.
  await page.goto('/');
  await foldTo(page, 'expanded');
  await page.getByTestId('nav-settings').click();
  await foldTo(page, 'rail');

  // The toggle inside settings used to do nothing at all; it unfolds that menu now.
  await page.getByTestId('sidebar-toggle').click();
  await expect(nav).toHaveAttribute('data-sidebar', 'expanded');

  // And the general menu is untouched by it — each area keeps its own answer.
  await page.getByTestId('nav-home').click();
  await expect(nav).toHaveAttribute('data-sidebar', 'expanded');
  await page.getByTestId('sidebar-toggle').click();
  await expect(nav).toHaveAttribute('data-sidebar', 'rail');

  await page.getByTestId('nav-settings').click();
  await expect(nav).toHaveAttribute('data-sidebar', 'expanded');
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
