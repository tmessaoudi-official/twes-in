// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test';

// The G0 scenario, kept: the real bundle, served by nginx, reaches the real API through the proxy and the API
// reaches the real database. Since G1a the root is guarded, so the status line lives on the login page.
test('the login page shows the product name and a healthy API', async ({ page }) => {
  await page.goto('/');
  await expect(page).toHaveURL(/\/login$/);
  await expect(page.getByRole('img', { name: 'twes-in' })).toBeVisible();
  await expect(page.getByTestId('api-status')).toContainText('opérationnelle');
});
