// SPDX-License-Identifier: AGPL-3.0-or-later
import AxeBuilder from '@axe-core/playwright';
import { expect, type Page, test } from '@playwright/test';
import { invitationTokenFor } from './mailpit';
import { signIn as signInAsOperator } from './session';
import { totp } from './totp';
import { toast } from './toast';

// Two-step verification through the real stack, for a freshly invited account. Never the shared operator: every
// other scenario computes the operator's codes from the authenticator the seed gave them.
const NEW_PASSWORD = 'a-long-enough-password';
// The SPA sends one random token per page load as a header; any value of the right shape is accepted from the page.
const CSRF = '0123456789abcdef0123456789abcdef';

async function signIn(page: Page, email: string, password: string): Promise<void> {
  await page.goto('/login');
  await page.getByTestId('email').fill(email);
  await page.getByTestId('password').fill(password);
  await page.getByTestId('submit').click();
}

async function signOut(page: Page): Promise<void> {
  await page.getByTestId('user-menu').click();
  await page.getByTestId('logout').click();
  await expect(page).toHaveURL(/\/login$/);
}

async function expectAccessible(page: Page, screen: string): Promise<void> {
  const results = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
    .analyze();
  const violations = results.violations.map(
    (violation) =>
      `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`,
  );
  expect(violations, screen).toEqual([]);
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
  await expect(page.getByTestId('two-factor-qr').locator('svg')).toBeVisible();
  const secret = (await page.getByTestId('two-factor-secret').innerText()).replace(/\s/g, '');
  await expectAccessible(page, 'two-factor, scan');
  await page.screenshot({ path: test.info().outputPath('two-factor-scan.png'), fullPage: true });

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
  await page.getByTestId('user-menu').click();
  await page.getByTestId('two-factor-link').click();
  await expect(page.getByTestId('two-factor-enabled')).toBeVisible();
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
  test.setTimeout(120_000);
  const owner = `mfa-owner-${Date.now()}@twes.local`;
  const name = `Secure ${Date.now()}`;

  // A throwaway company, so the requirement never reaches Demo or the operator every other scenario signs in as.
  await signInAsOperator(page);
  await expect(page).toHaveURL(/\/$/);
  const invited = await page.evaluate(
    async ([companyName, csrf, email]) => {
      const headers = { 'content-type': 'application/json', 'csrf-token': csrf };
      const created = await fetch('/api/companies', {
        method: 'POST',
        headers,
        body: JSON.stringify({
          name: companyName,
          countryCode: 'TN',
          currency: 'TND',
          locale: 'fr',
          timezone: 'Africa/Tunis',
        }),
      });
      const company = (await created.json()) as { id: string };
      const member = await fetch(`/api/platform/companies/${company.id}/owners`, {
        method: 'POST',
        headers,
        body: JSON.stringify({ email }),
      });
      return [created.status, member.status];
    },
    [name, CSRF, owner] as const,
  );
  expect(invited).toEqual([201, 201]);
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
});
