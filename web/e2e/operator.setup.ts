// SPDX-License-Identifier: AGPL-3.0-or-later
import { test as setup } from '@playwright/test';
import { OPERATOR_SESSION, signInWithCode } from './session';

// Runs once before the scenarios (playwright.config.ts); session.ts says why the operator signs in only once.
setup('the operator signs in with a code once for the whole run', async ({ page }) => {
  setup.setTimeout(90_000);
  await signInWithCode(page);
  await page.context().storageState({ path: OPERATOR_SESSION });
});
