// SPDX-License-Identifier: AGPL-3.0-or-later
import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { signIn } from './session';

// docs/SPEC.md § 8 row 48: the application is never silent about what it waits for.

test('a slow request shows the bar and says it is taking long, then everything clears', async ({
  page,
}) => {
  test.setTimeout(60_000);
  await signIn(page);
  await expect(page.getByTestId('greeting')).toBeVisible();

  await page.route('**/api/companies/*/customers**', async (route) => {
    await new Promise((resolve) => setTimeout(resolve, 9_000));
    await route.continue();
  });
  await page.getByTestId('nav-customers').click();

  await expect(page.getByTestId('activity-progress')).toBeVisible();
  await expect(page.getByTestId('activity-slow')).toBeVisible({ timeout: 12_000 });
  await expect(page.getByTestId('activity-slow')).toHaveAttribute('role', 'status');
  // What the bar shows sits in a landmark like the rest of the page (CI met the bar outside one, 2026-09-17).
  const outside = await new AxeBuilder({ page }).withRules(['region']).analyze();
  expect(
    outside.violations.flatMap((violation) => violation.nodes.map((node) => node.target.join(' '))),
  ).toEqual([]);
  await expect(page.getByTestId('activity-progress')).toHaveCount(0, { timeout: 15_000 });
  await expect(page.getByTestId('activity-slow')).toHaveCount(0);
});

test('when the service cannot be reached the page says so, tries again, and says when it is back', async ({
  page,
}) => {
  test.setTimeout(60_000);
  await signIn(page);
  await expect(page.getByTestId('greeting')).toBeVisible();

  // A request the home page sent before the outage would still answer during it, and rightly prove the API there.
  await page.waitForLoadState('networkidle');
  // All of the API at once, as an outage is: blocking only some paths lets the others prove it reachable in between.
  const down = /\/api\//;
  await page.route(down, (route) => route.abort('connectionrefused'));
  await page.getByTestId('nav-customers').click();

  const notice = page.getByTestId('activity-unavailable');
  await expect(notice).toBeVisible();
  await expect(notice).toHaveAttribute('role', 'alert');
  await expect(notice).toContainText(/Nouvelle tentative dans \d+ s/);

  await page.unroute(down);
  await page.getByTestId('activity-retry').click();
  await expect(notice).toHaveCount(0);
  // The announced toast: Material draws it in an aria-hidden holder first, then moves it into its live region.
  await expect(page.locator('[aria-live] [data-testid="toast"]')).toContainText(
    'Connexion rétablie',
  );
});

test('offline, the page says nothing can be saved', async ({ page, context }) => {
  await signIn(page);
  await expect(page.getByTestId('greeting')).toBeVisible();
  await context.setOffline(true);
  await expect(page.getByTestId('activity-offline')).toBeVisible();
  await context.setOffline(false);
  await expect(page.getByTestId('activity-offline')).toHaveCount(0);
});

test('a session that ends while a page is open sends the person to sign in, saying why', async ({
  page,
}) => {
  await signIn(page);
  await expect(page.getByTestId('greeting')).toBeVisible();
  await page.route(/\/api\/companies\//, (route) =>
    route.fulfill({
      status: 401,
      contentType: 'application/json',
      body: '{"code":"authentication_required"}',
    }),
  );
  // The home page's own requests are enough to meet the ended session; no click races them.
  await page.reload();

  await expect(page).toHaveURL(/\/login\?expired=1$/);
  await expect(page.getByTestId('login-expired')).toBeVisible();
});
