// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Locator, type Page, type Response } from '@playwright/test';
import { OPERATOR_TOTP_SECRET } from './session';
import { freshTotp, stepSpent } from './totp';

// The company's bank details, a member added or removed and a role changed each wait for the person to prove who they
// are again (docs/SPEC.md § 7, the step-up list), and a proof holds a few minutes of that sign-in. Most scenarios share
// the operator's session, so whether the question comes depends on what ran before: these helpers answer it when it
// comes and carry on when it does not. Where a second factor is required of the account, as of every operator, the
// proof takes the authenticator code beside the password: one from a step no code was spent in, since a code is
// refused once spent, as a replay. Every account the scenarios enrol holds the operators' secret.

const CSRF = '0123456789abcdef0123456789abcdef';

/**
 * After a change on the list was asked for on the screen: answers the step-up dialog if it opens, then lets `outcome`
 * (what the change shows once made, a toast most often) appear. Neither showing fails, as the change would have.
 */
export async function proveIfAsked(
  page: Page,
  password: string,
  outcome: Locator,
  secret: string = OPERATOR_TOTP_SECRET,
): Promise<void> {
  const field = page.getByTestId('step-up-password');
  await expect(field.or(outcome).first()).toBeVisible();
  if (await field.isVisible()) await answerStepUp(page, password, secret);
}

/**
 * Answers the open step-up dialog: the password, and the code of `secret`'s authenticator where the dialog asks one. A
 * code the API refuses is one another run spent (the setup's sign-in): the next step's is given once.
 */
export async function answerStepUp(
  page: Page,
  password: string,
  secret: string = OPERATOR_TOTP_SECRET,
): Promise<void> {
  await page.getByTestId('step-up-password').fill(password);
  const code = page.getByTestId('step-up-code');
  for (let attempt = 0; ; attempt++) {
    if (await code.isVisible()) await code.fill(await freshTotp(secret));
    const answered = page.waitForResponse(
      (response) =>
        new URL(response.url()).pathname === '/api/auth/step-up' &&
        response.request().method() === 'POST',
    );
    await page.getByTestId('step-up-confirm').click();
    const response = await answered;
    if (attempt > 0 || !(await codeRefused(response.status(), () => response.json()))) return;
    stepSpent(secret);
  }
}

/** Whether a proof answered that the code beside the password is missing or wrong. */
async function codeRefused(status: number, body: () => Promise<unknown>): Promise<boolean> {
  if (status !== 422) return false;
  const answer = await body();
  return (
    typeof answer === 'object' &&
    answer !== null &&
    (answer as { error?: unknown }).error === 'invalid_code'
  );
}

/** What the API answered a call made from inside the page. */
export interface Answer {
  readonly status: number;
  readonly body: unknown;
}

/** Whether the API held a change back until who is signed in proves it again, in either of its two shapes. */
function heldBack({ status, body }: Answer): boolean {
  if (status !== 403 || typeof body !== 'object' || body === null) return false;
  const { error, detail } = body as { error?: unknown; detail?: unknown };
  return (
    error === 'step_up_required' ||
    (typeof detail === 'string' && detail.startsWith('step_up_required:'))
  );
}

/** Calls the API from inside the page, which is the only way the session cookie travels (SameSite=Strict). */
async function send(page: Page, method: string, url: string, body: unknown): Promise<Answer> {
  return page.evaluate(
    async ([csrf, verb, path, payload]) => {
      const response = await fetch(path, {
        method: verb,
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: payload === null ? null : JSON.stringify(payload),
      });
      const text = await response.text();
      return { status: response.status, body: text === '' ? null : (JSON.parse(text) as unknown) };
    },
    [CSRF, method, url, body] as const,
  );
}

/**
 * Sends a change as the screen does, and proves who is signed in only when the API holds it back, then sends it once
 * more. Every attempt counts against the account's budget of five in five minutes, shared with sign-in codes, and the
 * scenarios run one after another on the operator's one session: a proof given in advance by each spent that budget
 * within a few scenarios, while one given when asked holds the next few minutes of them all.
 */
export async function sendProving(
  page: Page,
  password: string,
  method: string,
  url: string,
  body: unknown = null,
  secret: string = OPERATOR_TOTP_SECRET,
): Promise<Answer> {
  const answer = await send(page, method, url, body);
  if (!heldBack(answer)) return answer;
  await proveByApi(page, password, secret);
  return send(page, method, url, body);
}

/**
 * After a change was asked for on the screen, with `answered` waiting for the API's first answer to it: when that
 * answer held the change back, gives the proof in the dialog, which sends the change again. Resolves to the answer
 * that counts, the second one when there was a proof.
 */
export async function proveIfHeldBack(
  page: Page,
  password: string,
  answered: Promise<Response>,
  secret: string = OPERATOR_TOTP_SECRET,
): Promise<Response> {
  const response = await answered;
  const status = response.status();
  if (!heldBack({ status, body: status === 403 ? await response.json() : null })) return response;
  const again = page.waitForResponse(
    (next) =>
      next.url() === response.url() && next.request().method() === response.request().method(),
  );
  await answerStepUp(page, password, secret);
  return again;
}

/**
 * Proves who is signed in on this page, as the dialog does, the code beside the password where the session says the
 * step-up asks one. Only where the proof is always wanted, a session that has given none yet; elsewhere `sendProving`
 * and `proveIfHeldBack` prove only when the API asks.
 */
export async function proveByApi(
  page: Page,
  password: string,
  secret: string = OPERATOR_TOTP_SECRET,
): Promise<void> {
  const asksCode = await page.evaluate(
    async () =>
      ((await (await fetch('/api/auth/me')).json()) as { stepUpProof: { code: boolean } })
        .stepUpProof.code,
  );
  for (let attempt = 0; ; attempt++) {
    const code = asksCode ? await freshTotp(secret) : null;
    const answer = await page.evaluate(
      async ([csrf, secret, typed]) => {
        const response = await fetch('/api/auth/step-up', {
          method: 'POST',
          headers: { 'content-type': 'application/json', 'csrf-token': csrf },
          body: JSON.stringify(
            typed === null ? { password: secret } : { password: secret, code: typed },
          ),
        });
        const text = await response.text();
        return {
          status: response.status,
          body: text === '' ? null : (JSON.parse(text) as unknown),
        };
      },
      [CSRF, password, code] as const,
    );
    if (attempt === 0 && (await codeRefused(answer.status, async () => answer.body))) {
      stepSpent(secret);
      continue;
    }
    expect(answer.status).toBe(204);
    return;
  }
}
