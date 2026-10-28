// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test';
import { signIn } from './session';

// The length a new password must have is read from the API (GET /api/auth/password/rule) and said under the field as it
// is left, so a password the API would refuse is never sent. Nothing is written, so nothing is left behind.
test('a new password shorter than the API takes is said under it, and nothing is sent', async ({
  page,
}) => {
  await signIn(page);
  const rule = page.waitForResponse(
    (response) => new URL(response.url()).pathname === '/api/auth/password/rule',
  );
  await page.goto('/account?tab=security');
  const { minLength } = (await (await rule).json()) as { minLength: number };
  await expect(
    page.getByTestId('account-password-new').locator('xpath=ancestor::mat-form-field'),
  ).toContainText(String(minLength));

  let sent = false;
  page.on('request', (request) => {
    if (request.method() === 'PUT' && new URL(request.url()).pathname === '/api/auth/password') {
      sent = true;
    }
  });
  await page.getByTestId('account-password-current').fill('not-the-password');
  await page.getByTestId('account-password-new').fill('x'.repeat(minLength - 1));
  await page.getByTestId('account-password-again').fill('x'.repeat(minLength - 1));
  await expect(page.getByTestId('account-password-new-error')).toBeVisible();
  await page.getByTestId('account-password-submit').click();

  await expect(page.getByTestId('account-password-new')).toBeFocused();
  expect(sent).toBe(false);
});
