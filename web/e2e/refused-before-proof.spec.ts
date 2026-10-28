// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test';
import { signIn } from './session';

// Every platform write waits for the operator to prove who is at the screen, and the API asks that proof before it reads
// what was sent: a value the screen knows to be wrong is said under its field first, and no proof is asked for a write
// that would be refused afterwards. Nothing is written, so nothing is left behind.
test('a new company with no name and a wrong owner address is said wrong before any proof is asked', async ({
  page,
}) => {
  await signIn(page);
  await page.goto('/platform?tab=companies');
  await page.getByTestId('platform-company-new').click();
  await page.getByTestId('platform-company-owner').fill('------');
  let asked = false;
  page.on('request', (request) => {
    if (request.method() === 'POST' && new URL(request.url()).pathname === '/api/companies')
      asked = true;
  });

  await page.getByTestId('platform-company-create').click();

  await expect(page.getByTestId('platform-company-name-error')).toBeVisible();
  await expect(page.getByTestId('platform-company-owner-error')).toBeVisible();
  await expect(page.getByTestId('platform-company-name')).toBeFocused();
  await expect(page.getByTestId('step-up-password')).toHaveCount(0);
  expect(asked).toBe(false);
});
