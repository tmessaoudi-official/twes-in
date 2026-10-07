// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test';
import { inACompany, signIn } from './session';

const CSRF = '0123456789abcdef0123456789abcdef';

// A count is said in its own form through the app's translation compiler, never « (s) » and never the choice's raw
// source: a compiler left out of the app's providers would print `{count, plural, …}` on every such screen.
test('a count reads in the form French gives it', async ({ page }) => {
  await signIn(page);
  await inACompany(page, CSRF);
  await page.goto('/company/roles');

  const list = page.getByTestId('roles-list');
  await expect(list).toContainText(/\d+ membres?\b/);
  await expect(list).not.toContainText('plural');
  await expect(list).not.toContainText('(s)');
  const owners = list.locator('li').filter({ hasText: /propriétaire/i }).first();
  await expect(owners).toContainText(/\b1 membre\b(?!s)|\b([02-9]|\d{2,}) membres\b/);
});
