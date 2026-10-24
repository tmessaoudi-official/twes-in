// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { wcagViolations } from './axe';
import { invitationTokenFor } from './mailpit';
import { OPERATOR_PASSWORD, signIn as signInAsOperator } from './session';
import { proveIfAsked, sendProving } from './step-up';
import { totp } from './totp';
import { toast } from './toast';

// Two-step verification through the real stack, for a freshly invited account. Never the shared operator: every
// other scenario computes the operator's codes from the authenticator the seed gave them.
const NEW_PASSWORD = 'a-long-enough-password';

async function signIn(page: Page, email: string, password: string): Promise<void> {
  await page.goto('/login');
  await page.getByTestId('email').fill(email);
  await page.getByTestId('password').fill(password);
  await page.getByTestId('submit').click();
}

/** Adding or removing a factor asks who is at the screen first: the password, in the step-up dialog. */
async function prove(page: Page, password: string): Promise<void> {
  await expect(page.getByTestId('step-up-title')).toBeVisible();
  await page.getByTestId('step-up-password').fill(password);
  await page.getByTestId('step-up-confirm').click();
  await expect(page.getByTestId('step-up-title')).toHaveCount(0);
}

async function signOut(page: Page): Promise<void> {
  await page.getByTestId('user-menu').click();
  await page.getByTestId('logout').click();
  await expect(page).toHaveURL(/\/login$/);
}

/** The shared scan, which waits for an overlay's fade: a dialog read while it opens fails on colours nobody sees. */
async function expectAccessible(page: Page, screen: string): Promise<void> {
  expect(await wcagViolations(page), screen).toEqual([]);
}

/** Waits for the next 30-second step: a code is refused unless its step is newer than the last one spent. */
async function nextStep(page: Page): Promise<void> {
  await page.waitForTimeout(30_000 - (Date.now() % 30_000) + 1_000);
}

test('an account turns on two-step verification, replaces its recovery codes, then signs in with a code and with a recovery code', async ({
  page,
  request,
}) => {
  test.setTimeout(210_000);
  const invited = `two-factor-${Date.now()}@twes.local`;

  await signInAsOperator(page);
  await expect(page).toHaveURL(/\/$/);
  await page.getByTestId('nav-settings').click();
  await page.getByTestId('nav-members').click();
  await page.getByTestId('member-email').fill(invited);
  await page.getByTestId('member-add').click();
  await proveIfAsked(page, OPERATOR_PASSWORD, toast(page));
  await expect(toast(page)).toContainText('invitation');
  const token = await invitationTokenFor(request, invited);

  await page.context().clearCookies();
  await page.goto(`/invitations/${token}`);
  await page.getByTestId('invitation-name').fill('Second Factor');
  await page.getByTestId('invitation-password').fill(NEW_PASSWORD);
  await page.getByTestId('invitation-submit').click();
  await expect(page).toHaveURL(/\/login$/);

  // Turning it on, from the account menu.
  await signIn(page, invited, NEW_PASSWORD);
  await expect(page.getByTestId('greeting')).toContainText('Second Factor');
  await page.getByTestId('user-menu').click();
  await page.getByTestId('two-factor-link').click();
  await expect(page).toHaveURL(/\/two-factor$/);
  await prove(page, NEW_PASSWORD);
  await expect(page.getByTestId('two-factor-qr').locator('svg')).toBeVisible();
  const secret = (await page.getByTestId('two-factor-secret').innerText()).replace(/\s/g, '');
  await expectAccessible(page, 'two-factor, scan');
  await page.screenshot({ path: test.info().outputPath('two-factor-scan.png'), fullPage: true });
  // Nothing requires it of this account, so the page outside the menu offers a way out without setting anything up.
  await expect(page.getByTestId('two-factor-later')).toBeVisible();

  await page.getByTestId('two-factor-code').fill(totp(secret));
  await page.getByTestId('two-factor-submit').click();
  const recovery = page.getByTestId('two-factor-recovery-codes').locator('li');
  await expect(recovery).toHaveCount(10);
  const firstCodes = await recovery.allInnerTexts();
  await expectAccessible(page, 'two-factor, recovery codes');
  await page.screenshot({
    path: test.info().outputPath('two-factor-recovery-codes.png'),
    fullPage: true,
  });
  await page.getByTestId('two-factor-continue').click();
  await expect(page.getByTestId('greeting')).toBeVisible();

  // A new set of recovery codes, proven by a current authenticator code. Every attempt from here to the end counts
  // against the five a user gets in five minutes, which is why this comes before the sign-ins and not after.
  // An authenticator alone holds the account: Mon compte says so in words, with what to do, and the two-step page too.
  await page.goto('/account?tab=security');
  await expect(page.getByTestId('mfa-lockout-said')).toContainText(
    /seul appareil|single device|جهاز واحد/,
  );
  await expect(page.getByTestId('mfa-lockout-required')).toHaveCount(0);
  await expectAccessible(page, 'account, security, one device');
  await page.getByTestId('mfa-lockout-new-codes').click();
  await expect(page).toHaveURL(/\/two-factor$/);
  await expect(page.getByTestId('two-factor-enabled')).toBeVisible();
  await expect(page.getByTestId('mfa-lockout-said')).toBeVisible();
  await page.getByTestId('mfa-lockout-new-codes').click();
  await expect(page.getByTestId('two-factor-regenerate-code')).toBeFocused();
  await expectAccessible(page, 'two-factor, already on');
  await page.screenshot({
    path: test.info().outputPath('two-factor-regenerate.png'),
    fullPage: true,
  });
  await nextStep(page);
  await page.getByTestId('two-factor-regenerate-code').fill(totp(secret));
  await page.getByTestId('two-factor-regenerate-submit').click();
  await expect(recovery).toHaveCount(10);
  const codes = await recovery.allInnerTexts();
  expect(codes.filter((code) => firstCodes.includes(code))).toEqual([]);
  await page.screenshot({
    path: test.info().outputPath('two-factor-new-recovery-codes.png'),
    fullPage: true,
  });
  await page.getByTestId('two-factor-continue').click();
  await expect(page.getByTestId('greeting')).toBeVisible();

  // The password alone no longer signs in: the code step comes first.
  await signOut(page);
  await signIn(page, invited, NEW_PASSWORD);
  await expect(page.getByTestId('mfa-form')).toBeVisible();
  await expect(page).toHaveURL(/\/login$/);
  await expectAccessible(page, 'login, code step');
  await page.screenshot({ path: test.info().outputPath('login-code-step.png'), fullPage: true });
  await nextStep(page);
  await page.getByTestId('mfa-code').fill(totp(secret));
  await page.getByTestId('mfa-submit').click();
  await expect(page.getByTestId('greeting')).toContainText('Second Factor');

  // A recovery code signs in once, and only once.
  await signOut(page);
  await signIn(page, invited, NEW_PASSWORD);
  await page.getByTestId('mfa-code').fill(codes[0]!);
  await page.getByTestId('mfa-submit').click();
  await expect(page.getByTestId('greeting')).toContainText('Second Factor');

  await signOut(page);
  await signIn(page, invited, NEW_PASSWORD);
  await page.getByTestId('mfa-code').fill(codes[0]!);
  await page.getByTestId('mfa-submit').click();
  await expect(page.getByTestId('login-error')).toBeVisible();
  await expect(page.getByTestId('mfa-form')).toBeVisible();

  // A code from the replaced set is refused too: the fifth and last attempt this window allows.
  await page.getByTestId('mfa-code').fill(firstCodes[1]!);
  await page.getByTestId('mfa-submit').click();
  await expect(page.getByTestId('login-error')).toBeVisible();
  await expect(page.getByTestId('mfa-form')).toBeVisible();
});

test('a company that requires two-step verification sends its owner to set it up before anything else', async ({
  page,
  request,
}) => {
  test.setTimeout(210_000);
  const owner = `mfa-owner-${Date.now()}@twes.local`;
  const name = `Secure ${Date.now()}`;

  // A throwaway company, so the requirement never reaches Demo or the operator every other scenario signs in as.
  await signInAsOperator(page);
  await expect(page).toHaveURL(/\/$/);
  // Opening a company and every platform write wait for the operator's recent proof (docs/SPEC.md § 7), given
  // only when asked: every attempt counts against the operator's five in five minutes.
  const created = await sendProving(page, OPERATOR_PASSWORD, 'POST', '/api/companies', {
    name,
    countryCode: 'TN',
    currency: 'TND',
    locale: 'fr',
    timezone: 'Africa/Tunis',
  });
  expect(created.status).toBe(201);
  const owners = `/api/platform/companies/${(created.body as { id: string }).id}/owners`;
  expect(
    (await sendProving(page, OPERATOR_PASSWORD, 'POST', owners, { email: owner })).status,
  ).toBe(201);
  const token = await invitationTokenFor(request, owner);

  await page.context().clearCookies();
  await page.goto(`/invitations/${token}`);
  await page.getByTestId('invitation-name').fill('Secure Owner');
  await page.getByTestId('invitation-password').fill(NEW_PASSWORD);
  await page.getByTestId('invitation-submit').click();
  await expect(page).toHaveURL(/\/login$/);

  await signIn(page, owner, NEW_PASSWORD);
  await expect(page.getByTestId('company-name')).toHaveText(name);
  await page.getByTestId('nav-settings').click();
  await page.getByTestId('nav-company-security').click();
  await expect(page).toHaveURL(/\/company\/security$/);
  const requirement = page.getByTestId('security-mfa-toggle').getByRole('switch');
  await expect(requirement).toHaveAttribute('aria-checked', 'false');
  await expectAccessible(page, 'company security');
  await page.screenshot({ path: test.info().outputPath('company-security.png'), fullPage: true });

  // The owner has no factor, so turning the requirement on holds them back at once.
  await requirement.click();
  await expect(page).toHaveURL(/\/two-factor$/);
  await prove(page, NEW_PASSWORD);
  await expect(page.getByTestId('two-factor-required')).toBeVisible();
  await page.screenshot({
    path: test.info().outputPath('two-factor-required.png'),
    fullPage: true,
  });

  // Any other signed-in page sends them straight back.
  await page.goto('/');
  await expect(page).toHaveURL(/\/two-factor$/);

  const secret = (await page.getByTestId('two-factor-secret').innerText()).replace(/\s/g, '');
  await page.getByTestId('two-factor-code').fill(totp(secret));
  await page.getByTestId('two-factor-submit').click();
  await expect(page.getByTestId('two-factor-recovery-codes').locator('li')).toHaveCount(10);
  await page.getByTestId('two-factor-continue').click();
  await expect(page.getByTestId('greeting')).toContainText('Secure Owner');

  // Every factor can be removed but the last one a company requires (docs/SPEC.md § 7, 2026-09-22 09:12).
  await page.goto('/two-factor');
  // Its one device lost, only whoever hosts the installation could let this owner in again: the page says so.
  await expect(page.getByTestId('mfa-lockout-required')).toContainText(/hébergeur|hosts|مستضيف/);
  await page.getByTestId('two-factor-app-remove').click();
  // A removed factor cannot be put back, so the removal says so first (docs/SPEC.md § 7, 2026-09-26 23:04).
  await expect(page.getByTestId('confirm-kind')).toHaveAttribute('data-kind', 'definitif');
  await expect(page.getByTestId('confirm-message')).toContainText('ne seront plus acceptés');
  await expectAccessible(page, 'two-factor, asked before removing the authenticator');
  await page.screenshot({ path: test.info().outputPath('two-factor-remove-asked.png') });
  await page.getByTestId('confirm-run').click();
  const refused = page.getByTestId('two-factor-app-remove-error');
  await proveIfAsked(page, NEW_PASSWORD, refused);
  await expect(refused).toContainText('exige');
  await page.screenshot({ path: test.info().outputPath('two-factor-kept.png'), fullPage: true });
  await expect(page.getByTestId('two-factor-app-remove')).toBeVisible();
  await page.goto('/');
  await expect(page.getByTestId('greeting')).toContainText('Secure Owner');

  // Turning the requirement off weakens every member's sign-in, so it waits for a proof, which a sign-in never
  // carries: a fresh sign-in is asked, a declined proof leaves the requirement on, and a given one turns it off.
  await signOut(page);
  await signIn(page, owner, NEW_PASSWORD);
  await nextStep(page);
  await page.getByTestId('mfa-code').fill(totp(secret));
  await page.getByTestId('mfa-submit').click();
  await expect(page.getByTestId('greeting')).toContainText('Secure Owner');
  await page.goto('/company/security');
  await expect(requirement).toHaveAttribute('aria-checked', 'true');
  await requirement.click();
  await expect(page.getByTestId('step-up-intro')).toContainText('ne plus exiger');
  await page.getByTestId('step-up-cancel').click();
  await expect(page.getByTestId('step-up-title')).toHaveCount(0);
  await expect(requirement).toHaveAttribute('aria-checked', 'true');
  await requirement.click();
  await prove(page, NEW_PASSWORD);
  await expect(requirement).toHaveAttribute('aria-checked', 'false');

  // Required no more, the authenticator goes, a phone lost or replaced, and the page offers to set one up again.
  await page.goto('/two-factor');
  await page.getByTestId('two-factor-app-remove').click();
  await page.getByTestId('confirm-run').click();
  const scan = page.getByTestId('two-factor-qr');
  await proveIfAsked(page, NEW_PASSWORD, scan);
  await expect(scan.locator('svg')).toBeVisible();
  await expectAccessible(page, 'two-factor, after removing the authenticator');
  await page.screenshot({ path: test.info().outputPath('two-factor-removed.png'), fullPage: true });
  // Required no more, the page offers « Plus tard » rather than signing out, and the shell signs out.
  await page.getByTestId('two-factor-later').click();
  await expect(page).not.toHaveURL(/\/two-factor/);
  await signOut(page);
  await signIn(page, owner, NEW_PASSWORD);
  await expect(page.getByTestId('greeting')).toContainText('Secure Owner');
});
