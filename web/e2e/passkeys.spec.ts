// SPDX-License-Identifier: AGPL-3.0-or-later
import AxeBuilder from '@axe-core/playwright';
import { expect, type Page, test } from '@playwright/test';
import { invitationTokenFor } from './mailpit';

// A passkey is bound to a domain, and Chromium refuses an IP address as one, so this file talks to the stack as
// localhost whatever address the other files use. The API allows exactly that origin (APP_WEBAUTHN_ORIGINS).
test.use({
  baseURL: (process.env['BASE_URL'] ?? 'http://127.0.0.1:8090').replace('127.0.0.1', 'localhost'),
});

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

/** Chromium's virtual authenticator: a platform authenticator that verifies the user and needs no touch. */
async function addVirtualAuthenticator(page: Page): Promise<void> {
  const cdp = await page.context().newCDPSession(page);
  await cdp.send('WebAuthn.enable');
  await cdp.send('WebAuthn.addVirtualAuthenticator', {
    options: {
      protocol: 'ctap2',
      transport: 'internal',
      hasResidentKey: true,
      hasUserVerification: true,
      isUserVerified: true,
      automaticPresenceSimulation: true,
    },
  });
}

test('an account adds a passkey, signs in with it, and removes it', async ({ page, request }) => {
  test.setTimeout(150_000);
  await addVirtualAuthenticator(page);
  const invited = `passkey-${Date.now()}@twes.local`;

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
  await page.getByTestId('invitation-name').fill('Passkey Owner');
  await page.getByTestId('invitation-password').fill(NEW_PASSWORD);
  await page.getByTestId('invitation-submit').click();
  await expect(page).toHaveURL(/\/login$/);

  // The first passkey is the account's first factor, so it comes with the recovery codes.
  await signIn(page, invited, NEW_PASSWORD);
  await expect(page.getByTestId('greeting')).toContainText('Passkey Owner');
  await page.getByTestId('user-menu').click();
  await page.getByTestId('two-factor-link').click();
  await expect(page).toHaveURL(/\/two-factor$/);
  await expect(page.getByTestId('two-factor-passkeys-none')).toBeVisible();
  await page.getByTestId('two-factor-passkey-name').fill('Work laptop');
  await page.getByTestId('two-factor-passkey-add').click();
  await expect(page.getByTestId('two-factor-recovery-codes').locator('li')).toHaveCount(10);
  await page.screenshot({
    path: test.info().outputPath('passkey-recovery-codes.png'),
    fullPage: true,
  });
  await page.getByTestId('two-factor-continue').click();
  await expect(page.getByTestId('greeting')).toBeVisible();

  // The password alone no longer signs in; the passkey finishes it.
  await signOut(page);
  await signIn(page, invited, NEW_PASSWORD);
  await expect(page.getByTestId('mfa-form')).toBeVisible();
  await expectAccessible(page, 'login, code step with a passkey');
  await page.screenshot({ path: test.info().outputPath('login-passkey-step.png'), fullPage: true });
  await page.getByTestId('mfa-passkey').click();
  await expect(page.getByTestId('greeting')).toContainText('Passkey Owner');

  // The page again: the passkey is listed, and replacing the codes, which takes an authenticator code, is not offered.
  await page.getByTestId('user-menu').click();
  await page.getByTestId('two-factor-link').click();
  await expect(page.getByTestId('two-factor-enabled')).toBeVisible();
  await expect(page.getByTestId('two-factor-passkey-name-label')).toHaveText(['Work laptop']);
  await expect(page.getByTestId('two-factor-regenerate-form')).toHaveCount(0);
  await expect(page.getByTestId('two-factor-app-start')).toBeVisible();
  await expectAccessible(page, 'two-factor, passkeys');
  await page.screenshot({
    path: test.info().outputPath('two-factor-passkeys.png'),
    fullPage: true,
  });

  // This device already holds a passkey for the account, and says so rather than making a second one.
  await page.getByTestId('two-factor-passkey-name').fill('Same laptop');
  await page.getByTestId('two-factor-passkey-add').click();
  await expect(page.getByTestId('two-factor-passkey-error')).toBeVisible();
  await expect(page.getByTestId('two-factor-passkey-name-label')).toHaveText(['Work laptop']);

  // Removing the last factor puts the account back to having none, and the authenticator set-up starts again.
  await page.getByTestId('two-factor-passkey-remove').click();
  await expect(page.getByTestId('two-factor-passkeys-none')).toBeVisible();
  await expect(page.getByTestId('two-factor-qr').locator('svg')).toBeVisible();
  await page.getByTestId('two-factor-logout').click();
  await signIn(page, invited, NEW_PASSWORD);
  await expect(page.getByTestId('greeting')).toContainText('Passkey Owner');
});
