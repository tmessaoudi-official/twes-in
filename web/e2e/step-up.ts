// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Locator, type Page } from '@playwright/test';

// The company's bank details, a member added or removed and a role changed each wait for the person to prove who they
// are again (docs/SPEC.md § 7, the step-up list), and a proof holds a few minutes of that sign-in. Most scenarios share
// the operator's session, so whether the question comes depends on what ran before: these helpers answer it when it
// comes and carry on when it does not.

const CSRF = '0123456789abcdef0123456789abcdef';

/**
 * After a change on the list was asked for on the screen: answers the step-up dialog if it opens, then lets `outcome`
 * (what the change shows once made, a toast most often) appear. Neither showing fails, as the change would have.
 */
export async function proveIfAsked(page: Page, password: string, outcome: Locator): Promise<void> {
  const field = page.getByTestId('step-up-password');
  await expect(field.or(outcome).first()).toBeVisible();
  if (await field.isVisible()) {
    await field.fill(password);
    await page.getByTestId('step-up-confirm').click();
  }
}

/** Before a scenario's own call to a change on the list: proves who is signed in on this page, as the dialog does. */
export async function proveByApi(page: Page, password: string): Promise<void> {
  const status = await page.evaluate(
    async ([csrf, secret]) =>
      (
        await fetch('/api/auth/step-up', {
          method: 'POST',
          headers: { 'content-type': 'application/json', 'csrf-token': csrf },
          body: JSON.stringify({ password: secret }),
        })
      ).status,
    [CSRF, password] as const,
  );
  expect(status).toBe(204);
}
