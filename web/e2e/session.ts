// SPDX-License-Identifier: AGPL-3.0-or-later
import { readFileSync } from 'node:fs';
import { type BrowserContext, expect, type Page } from '@playwright/test';
import { iconFontLoaded } from './icon-font';
import { totp } from './totp';

// The seeded operator must carry a second factor (docs/SPEC.md § 7, 2026-09-15, S3), and every code check counts
// against five per account in five minutes, successes included. So the run signs the operator in with a code once,
// in the setup project (operator.setup.ts), and every scenario that only needs "the operator, signed in" reuses that
// session, its proofs of who is at the screen counting against the same five. A scenario about signing in itself, or
// one that moves the session to another company, signs in afresh as a second operator, `CODES_OPERATOR_EMAIL`, so its
// codes never take the first one's proofs. Rerunning locally inside five minutes can still meet the limit.
// The credentials and the secret are the ones `make seed` and the CI e2e job pass to `app:seed`, the same for both.
export const OPERATOR_EMAIL = process.env['E2E_EMAIL'] ?? 'operator@twes.local';
/** The operator the scenarios signing in with a code use: an owner of Demo too, seeded beside the first. */
export const CODES_OPERATOR_EMAIL = process.env['E2E_CODES_EMAIL'] ?? 'codes@twes.local';
export const OPERATOR_PASSWORD = process.env['E2E_PASSWORD'] ?? 'twes-operator-dev';
const OPERATOR_TOTP_SECRET = process.env['E2E_TOTP_SECRET'] ?? 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

/** Where the setup project leaves the operator's session, relative to web/ (gitignored). */
export const OPERATOR_SESSION = 'playwright/.auth/operator.json';

/** `COOKIE_NOTICE_KEY` in web/src/app/shared/legal/cookie-notice.ts: e2e imports nothing from the app. */
export const NOTICE_CLOSED = 'twes.cookie-notice';

type SavedCookie = Awaited<ReturnType<BrowserContext['storageState']>>['cookies'][number];

const leaving = new WeakSet<Page>();

/**
 * A reload or a `goto` while a form holds unsaved work meets the browser's own « leave the page? » (docs/SPEC.md § 7,
 * 2026-09-21 18:20), which Playwright would dismiss, keeping the page and timing the scenario out: a scenario leaves
 * as a person who chose to. Any other dialog is dismissed, as Playwright does with no listener.
 */
export function leaveWhenAsked(page: Page): void {
  if (leaving.has(page)) return;
  leaving.add(page);
  page.on('dialog', (dialog) => {
    void (dialog.type() === 'beforeunload' ? dialog.accept() : dialog.dismiss());
  });
}

/** The operator, signed in on the session the setup project opened, on whichever host this page talks to. */
export async function signIn(page: Page): Promise<void> {
  leaveWhenAsked(page);
  const { cookies } = JSON.parse(readFileSync(OPERATOR_SESSION, 'utf8')) as {
    cookies: SavedCookie[];
  };
  await page.goto('/login');
  // passkeys.spec.ts talks to the stack as localhost, and a cookie belongs to the host it was set by.
  const host = new URL(page.url()).hostname;
  await page.context().addCookies(cookies.map((cookie) => ({ ...cookie, domain: host })));
  // A scenario is not a first visit: the cookie notice (row 149) was closed long ago, in every context it opens.
  await page.evaluate((key) => localStorage.setItem(key, 'closed'), NOTICE_CLOSED);
  await page.goto('/');
  await expect(page).toHaveURL(/\/$/);
  // A scenario's next reload or page must not cut short the home's icon font, which WebKit then keeps as failed.
  await iconFontLoaded(page);
}

/**
 * Makes sure the session is working in Demo. A sign-in opens the company pinned, else the one last used, else the
 * first by name (docs/SPEC.md § 7, 2026-09-25 09:03), so after `make fixtures` it may open another of the operator's
 * companies than the one the scenarios' data lives in. CI seeds Demo alone and this does nothing; locally it is what
 * keeps a run about invoices about Demo's invoices, and it gives the proof an account in several companies owes Demo.
 */
export async function inACompany(page: Page, csrf: string): Promise<void> {
  const proof = await page.evaluate(async (token) => {
    type Me = {
      company: { id: string; name: string } | null;
      companyProof: { passkey: boolean; code: boolean; password: boolean } | null;
    };
    const read = async () => (await (await fetch('/api/auth/me')).json()) as Me;
    let me = await read();
    if (me.company?.name !== 'Demo') {
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
      me = await read();
    }
    return me.company === null || me.companyProof === null
      ? null
      : { companyId: me.company.id, code: me.companyProof.code };
  }, csrf);
  if (proof === null) return;
  // An account in two companies or more proves itself for each it enters, once per sign-in; a code the sign-in spent
  // is refused as a replay, so the proof is tried again with the next step's code, thirty seconds on: every attempt
  // counts against the operator's five in five minutes, which the step-ups share.
  await expect(async () => {
    const status = await page.evaluate(
      async ([companyId, token, password, code]) =>
        (
          await fetch(`/api/companies/${companyId}/proof`, {
            method: 'POST',
            headers: { 'content-type': 'application/json', 'csrf-token': token },
            body: JSON.stringify(code === null ? { password } : { password, code }),
          })
        ).status,
      [
        proof.companyId,
        csrf,
        OPERATOR_PASSWORD,
        proof.code ? totp(OPERATOR_TOTP_SECRET) : null,
      ] as const,
    );
    expect(status).toBe(204);
  }).toPass({ intervals: [31_000], timeout: 70_000 });
}

/** What a PUT of the company profile accepts, in the order the API reads it; the version read is sent back. */
const PROFILE_FIELDS = [
  'version',
  'legalName',
  'legalForm',
  'identifiers',
  'addressLine1',
  'addressLine2',
  'postalCode',
  'city',
  'email',
  'phone',
  'website',
  'iban',
  'bic',
  'vatRegime',
  'invoiceFooterText',
  'latePenaltyText',
];

/**
 * Gives the working company the legal name, address and matricule fiscal an invoice must print, keeping whatever it
 * already holds. The seed creates Demo with an empty profile, as a new company is, and issuing refuses a company that
 * cannot be named, so the scenarios that issue would otherwise depend on company-profile.spec having run first.
 */
export async function namedAsTheLawAsks(page: Page, csrf: string): Promise<void> {
  await page.evaluate(
    async ([token, fields]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const path = `/api/companies/${me.company.id}/profile`;
      const held = (await (await fetch(path)).json()) as Record<string, unknown>;
      const identifiers = (held['identifiers'] ?? {}) as Record<string, string>;
      if (
        held['legalName'] &&
        held['addressLine1'] &&
        held['city'] &&
        identifiers['matricule_fiscal']
      )
        return;
      const profile = Object.fromEntries(fields.map((key) => [key, held[key] ?? null]));
      const answered = await fetch(path, {
        method: 'PUT',
        headers: { 'content-type': 'application/json', 'csrf-token': token },
        body: JSON.stringify({
          ...profile,
          legalName: held['legalName'] ?? 'Demo SARL',
          identifiers: {
            ...identifiers,
            matricule_fiscal: identifiers['matricule_fiscal'] ?? '1234567A/B/M/000',
          },
          addressLine1: held['addressLine1'] ?? 'Rue de Marseille',
          postalCode: held['postalCode'] ?? '1000',
          city: held['city'] ?? 'Tunis',
          vatRegime: held['vatRegime'] ?? 'standard',
        }),
      });
      if (!answered.ok)
        throw new Error(`naming the company answered ${answered.status}: ${await answered.text()}`);
    },
    [csrf, PROFILE_FIELDS] as const,
  );
}

/**
 * What is coming is hidden until someone shows it. The scenarios about it, and the ones measuring a bar's width with its
 * « Bientôt » actions in it, are written for a company that shows it: set at the company's level, which a scenario
 * forgetting its own presentation choices leaves alone, and which « Masquer ce qui arrive » still overrides for a person.
 */
export async function showsWhatIsComing(page: Page, csrf: string): Promise<void> {
  await page.evaluate(async (token) => {
    const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
    const answered = await fetch(
      `/api/companies/${me.company.id}/settings/presentation.show-coming`,
      {
        method: 'PUT',
        headers: { 'content-type': 'application/json', 'csrf-token': token },
        body: JSON.stringify({ level: 'company', value: true }),
      },
    );
    if (!answered.ok)
      throw new Error(
        `showing what is coming answered ${answered.status}: ${await answered.text()}`,
      );
  }, csrf);
}

/** Signs the operator in from the login page, the password and then a code, on a session of its own. */
export async function signInWithCode(page: Page, email: string = OPERATOR_EMAIL): Promise<void> {
  await page.goto('/login');
  await page.getByTestId('email').fill(email);
  await page.getByTestId('login-continue').click();
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
