// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { signIn } from './session';

// The sidebar's desktop state through the real stack: the [ key turns it into a rail of named icons, the choice is
// the person's presentation setting and outlives a reload. One database is shared by the whole suite, so the
// operator's own choice is forgotten before and after.
const CSRF = '0123456789abcdef0123456789abcdef';
const SIDEBAR = 'presentation.sidebar';

async function forgetSidebar(page: Page): Promise<void> {
  const status = await page.evaluate(
    async ([csrf, key]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const response = await fetch(`/api/companies/${me.company.id}/settings/${key}?level=user`, {
        method: 'DELETE',
        headers: { 'csrf-token': csrf },
      });
      return response.status;
    },
    [CSRF, SIDEBAR],
  );
  expect(status).toBe(204);
}

test('the [ key collapses the sidebar to a rail of named icons, which outlives a reload', async ({
  page,
}) => {
  await page.setViewportSize({ width: 1280, height: 800 });
  await signIn(page);
  await forgetSidebar(page);
  try {
    await page.reload();
    const nav = page.getByTestId('shell-nav');
    await expect(nav).toHaveAttribute('data-sidebar', 'expanded');

    const saved = page.waitForResponse(
      (response) =>
        response.url().includes(`/settings/${SIDEBAR}`) && response.request().method() === 'PUT',
    );
    await page.keyboard.press('BracketLeft');
    await expect(nav).toHaveAttribute('data-sidebar', 'rail');
    expect((await saved).ok()).toBe(true);
    await expect(page.getByTestId('nav-home')).toHaveAccessibleName(/Accueil/);
    // The page takes back the width the sidebar gave up.
    await expect
      .poll(async () => (await page.locator('#main-content').boundingBox())?.x ?? Infinity)
      .toBeLessThan(200);

    await page.reload();
    await expect(nav).toHaveAttribute('data-sidebar', 'rail');
    await page.getByTestId('sidebar-toggle').click();
    await expect(nav).toHaveAttribute('data-sidebar', 'expanded');
  } finally {
    await forgetSidebar(page);
  }
});

test('the navigation follows the window: a rail of icons on a tablet, a bottom bar on a phone', async ({
  page,
}) => {
  await page.setViewportSize({ width: 900, height: 800 });
  await signIn(page);
  const nav = page.getByTestId('shell-nav');
  await expect(nav).toHaveAttribute('data-window', 'medium');
  await expect(nav).toHaveAttribute('data-sidebar', 'rail');
  await expect(page.getByTestId('sidebar-toggle')).toHaveCount(0);
  await expect(page.getByTestId('bottom-bar')).toHaveCount(0);

  await page.setViewportSize({ width: 390, height: 844 });
  const bar = page.getByTestId('bottom-bar');
  await expect(bar).toBeVisible();
  await expect(page.getByTestId('bottom-nav-home')).toHaveAttribute('aria-current', 'page');
  const box = await bar.boundingBox();
  expect(box && box.y + box.height).toBeCloseTo(844, 0);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});

test('on a phone the settings list stands alone, a setting opens without it, and the way back returns to it', async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await signIn(page);
  // One way in, and on a phone the drawer IS "Plus": no gear in the bar, nothing in the account menu either
  // (design review finding 8).
  await expect(page.getByTestId('settings-gear')).toHaveCount(0);
  await page.getByTestId('user-menu').click();
  await expect(page.getByTestId('account-settings')).toHaveCount(0);
  await page.keyboard.press('Escape');
  await page.getByTestId('menu-toggle').click();
  await page.getByTestId('nav-settings').click();
  await expect(page).toHaveURL(/\/company$/);
  const nav = page.getByTestId('settings-nav');
  await expect(nav).toBeVisible();
  await expect(page.getByTestId('settings-index')).toBeHidden();

  await page.getByTestId('settings-filter').fill('membres');
  await expect(page.getByTestId('nav-company-profile')).toHaveCount(0);
  await page.getByTestId('nav-members').click();
  await expect(page.getByTestId('members-title')).toBeVisible();
  await expect(nav).toBeHidden();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);

  await page.getByTestId('settings-back').click();
  await expect(page).toHaveURL(/\/company$/);
  await expect(nav).toBeVisible();

  await page.setViewportSize({ width: 1280, height: 800 });
  await expect(page.getByTestId('settings-back')).toBeHidden();
  await expect(page.getByTestId('settings-index')).toBeVisible();
});

test('the rail and the top bar keep every control clear of the next, down to the narrowest labelled window', async ({
  page,
}) => {
  await signIn(page);
  // The seeded operator's short name and company leave room a long one would not; CI met the overlap with both long.
  for (const width of [1200, 1280, 1600, 900]) {
    await page.setViewportSize({ width, height: 800 });
    await expect(page.getByTestId('user-menu')).toBeVisible();
    // A control another one covers cannot be clicked: the account button once lay over the gear at 1280 px (CI, row 37).
    const boxes = await page.evaluate(() =>
      [
        ...document.querySelectorAll<HTMLElement>(
          '.twes-shell-bar button, .twes-shell-bar a, .twes-rail > button, .twes-rail-foot > button',
        ),
      ]
        .filter((control) => control.offsetParent !== null)
        .map((control) => {
          const box = control.getBoundingClientRect();
          return {
            id: control.getAttribute('data-testid') ?? control.textContent?.trim() ?? '?',
            left: box.left,
            right: box.right,
            top: box.top,
            bottom: box.bottom,
          };
        }),
    );
    const overlaps = boxes.flatMap((a, i) =>
      boxes
        .slice(i + 1)
        .filter(
          (b) =>
            a.left < b.right - 1 &&
            b.left < a.right - 1 &&
            a.top < b.bottom - 1 &&
            b.top < a.bottom - 1,
        )
        .map((b) => `${a.id} × ${b.id}`),
    );
    expect(overlaps, `${width} px`).toEqual([]);
    expect(
      await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
      `${width} px`,
    ).toBe(true);
  }
});
