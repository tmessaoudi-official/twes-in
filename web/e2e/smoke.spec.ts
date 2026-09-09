// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test';

// The one G0 scenario: the real bundle, served by nginx, reaches the real API through the proxy and the
// API reaches the real database. Anything short of "opérationnelle" means one link of that chain is broken.
test('the home page shows the product name and a healthy API', async ({ page }) => {
  await page.goto('/');
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('twes-in');
  await expect(page.getByTestId('api-status')).toContainText('opérationnelle');
});
