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
