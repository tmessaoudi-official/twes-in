// SPDX-License-Identifier: AGPL-3.0-or-later
import AxeBuilder from '@axe-core/playwright';
import { expect, type Page, test } from '@playwright/test';
import { invitationTokenFor } from './mailpit';
import { totp } from './totp';

// Two-step verification through the real stack, for a freshly invited account. Never the shared operator: every
// other scenario signs in as the operator with a password alone, and enrolling it would break them all.
const EMAIL = process.env['E2E_EMAIL'] ?? 'operator@twes.local';
const PASSWORD = process.env['E2E_PASSWORD'] ?? 'twes-operator-dev';
const NEW_PASSWORD = 'a-long-enough-password';

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

test('an account turns on two-step verification, then signs in with a code and with a recovery code', async ({
  page,
  request,
}) => {
  test.setTimeout(150_000);
  const invited = `two-factor-${Date.now()}@twes.local`;

  await signIn(page, EMAIL, PASSWORD);
  await expect(page).toHaveURL(/\/$/);
  await page.getByTestId('settings-gear').click();
  await page.getByTestId('nav-members').click();
  await page.getByTestId('member-email').fill(invited);
  await page.getByTestId('member-add').click();
  await expect(page.getByTestId('members-added')).toContainText('invitation');
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
  const codes = await recovery.allInnerTexts();
  await expectAccessible(page, 'two-factor, recovery codes');
  await page.screenshot({
    path: test.info().outputPath('two-factor-recovery-codes.png'),
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
});
