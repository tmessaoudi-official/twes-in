// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test';
import { wcagViolations } from './axe';
import { signIn } from './session';

// docs/SPEC.md § 7, 2026-09-26 08:52 (row 147): the copyright and legal links close every page, signed out or in,
// inside the page so they scroll with it; each link opens its page, open to anyone.

test('the legal line closes the sign-in page and leads to each legal page', async ({ page }) => {
  await page.goto('/login');
  const line = page.getByTestId('legal-footer');
  await expect(line).toBeVisible();
  await expect(line.getByTestId('legal-copyright')).toContainText(`© ${new Date().getFullYear()}`);
  await expect(line.getByRole('link')).toHaveCount(10);

  await line.getByTestId('legal-link-mentions').click();
  await expect(page).toHaveURL(/\/legal\/mentions$/);
  await expect(page.getByTestId('legal-title')).toHaveText('Mentions légales');
  await expect(page.getByTestId('legal-draft')).toBeVisible();
  expect(await wcagViolations(page)).toEqual([]);

  // The licence leads to the source page, as the AGPL asks of a network service.
  await page.getByTestId('legal-licence').click();
  await expect(page).toHaveURL(/\/legal\/source$/);
  await expect(page.getByTestId('legal-title')).toHaveText('Code source et licences');
});

test('the legal line closes a signed-in page, after its content, and a settings page beside its list', async ({
  page,
}) => {
  await signIn(page);
  const line = page.locator('main').getByTestId('legal-footer');
  await expect(line).toBeVisible();
  // It is the page's last thing, below what the page shows, not a bar pinned over it.
  const [main, footer] = await Promise.all([
    page.locator('main').boundingBox(),
    line.boundingBox(),
  ]);
  expect(footer!.y + footer!.height).toBeLessThanOrEqual(main!.y + main!.height + 1);
  expect(await wcagViolations(page)).toEqual([]);

  await page.goto('/members');
  await expect(page.getByTestId('settings-page').getByTestId('legal-footer')).toBeVisible();
  await expect(page.getByTestId('legal-footer')).toHaveCount(1);
});
