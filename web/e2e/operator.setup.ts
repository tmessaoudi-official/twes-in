// SPDX-License-Identifier: AGPL-3.0-or-later
import { test as setup } from '@playwright/test';
import { inACompany, namedAsTheLawAsks, OPERATOR_SESSION, signInWithCode } from './session';

const CSRF = '0123456789abcdef0123456789abcdef';

// Runs once before the scenarios (playwright.config.ts); session.ts says why the operator signs in only once, and why
// the company the scenarios work in is named before any of them issues an invoice.
setup('the operator signs in with a code once for the whole run', async ({ page }) => {
  setup.setTimeout(90_000);
  await signInWithCode(page);
  await inACompany(page, CSRF);
  await namedAsTheLawAsks(page, CSRF);
  await page.context().storageState({ path: OPERATOR_SESSION });
});
