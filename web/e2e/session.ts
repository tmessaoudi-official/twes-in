// SPDX-License-Identifier: AGPL-3.0-or-later
import { readFileSync } from 'node:fs';
import { type BrowserContext, expect, type Page } from '@playwright/test';
import { totp } from './totp';

// The seeded operator must carry a second factor (docs/SPEC.md § 7, 2026-09-15, S3), and every code check counts
// against five per account in five minutes, successes included. So the run signs the operator in with a code once,
// in the setup project (operator.setup.ts), and every scenario that only needs "the operator, signed in" reuses that
// session. A scenario about signing in itself, or one that moves the session to another company, signs in afresh:
// two a run, plus the setup's. Rerunning locally inside five minutes can therefore meet the limit.
// The credentials and the secret are the ones `make seed` and the CI e2e job pass to `app:seed`.
export const OPERATOR_EMAIL = process.env['E2E_EMAIL'] ?? 'operator@twes.local';
export const OPERATOR_PASSWORD = process.env['E2E_PASSWORD'] ?? 'twes-operator-dev';
const OPERATOR_TOTP_SECRET = process.env['E2E_TOTP_SECRET'] ?? 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

/** Where the setup project leaves the operator's session, relative to web/ (gitignored). */
export const OPERATOR_SESSION = 'playwright/.auth/operator.json';

type SavedCookie = Awaited<ReturnType<BrowserContext['storageState']>>['cookies'][number];

/** The operator, signed in on the session the setup project opened, on whichever host this page talks to. */
export async function signIn(page: Page): Promise<void> {
  const { cookies } = JSON.parse(readFileSync(OPERATOR_SESSION, 'utf8')) as {
    cookies: SavedCookie[];
  };
  await page.goto('/login');
  // passkeys.spec.ts talks to the stack as localhost, and a cookie belongs to the host it was set by.
  const host = new URL(page.url()).hostname;
  await page.context().addCookies(cookies.map((cookie) => ({ ...cookie, domain: host })));
  await page.goto('/');
  await expect(page).toHaveURL(/\/$/);
}

/**
 * Makes sure the session is working in Demo. A sign-in opens the company pinned, else the one last used, else the
 * first by name (docs/SPEC.md § 7, 2026-09-25 09:03), so after `make fixtures` it may open another of the operator's
 * companies than the one the scenarios' data lives in. CI seeds Demo alone and this does nothing; locally it is what
 * keeps a run about invoices about Demo's invoices.
 */
export async function inACompany(page: Page, csrf: string): Promise<void> {
  await page.evaluate(async (token) => {
    const me = (await (await fetch('/api/auth/me')).json()) as {
      company: { id: string; name: string } | null;
    };
    if (me.company?.name === 'Demo') return;
    const answered = (await (await fetch('/api/me/companies')).json()) as
      { member: { companyId: string; name: string }[] } | { companyId: string; name: string }[];
    const mine = Array.isArray(answered) ? answered : answered.member;
    const demo = mine.find((row) => row.name === 'Demo') ?? mine[0];
    if (demo === undefined) throw new Error('the operator belongs to no company at all');
    const moved = await fetch('/api/me/company', {
      method: 'POST',
      headers: { 'content-type': 'application/json', 'csrf-token': token },
      body: JSON.stringify({ companyId: demo.companyId }),
    });
    if (!moved.ok)
      throw new Error(`choosing ${demo.name} answered ${moved.status}: ${await moved.text()}`);
  }, csrf);
}

/** Signs the operator in from the login page, the password and then a code, on a session of its own. */
export async function signInWithCode(page: Page): Promise<void> {
  await page.goto('/login');
  await page.getByTestId('email').fill(OPERATOR_EMAIL);
  await page.getByTestId('password').fill(OPERATOR_PASSWORD);
  await page.getByTestId('submit').click();
  await enterOperatorCode(page);
  await expect(page).toHaveURL(/\/$/);
}

/**
 * Answers the code step with a code from a step nobody has spent yet: the setup's, or a previous run's, is refused
 * as a replay. Waiting for the next step costs up to thirty seconds, so a scenario calling this needs the time.
 */
export async function enterOperatorCode(page: Page): Promise<void> {
  await expect(page.getByTestId('mfa-form')).toBeVisible();
  await page.waitForTimeout(30_000 - (Date.now() % 30_000) + 1_000);
  await page.getByTestId('mfa-code').fill(totp(OPERATOR_TOTP_SECRET));
  await page.getByTestId('mfa-submit').click();
}
