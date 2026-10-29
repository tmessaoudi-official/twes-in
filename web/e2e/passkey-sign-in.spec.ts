// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { wcagViolations } from './axe';
import { invitationTokenFor } from './mailpit';
import { OPERATOR_PASSWORD, signIn as signInAsOperator } from './session';
import { proveIfAsked } from './step-up';
import { toast } from './toast';
import { totp } from './totp';
import { addVirtualAuthenticator, passkeysUnderTheField } from './virtual-authenticator';

// A passkey is bound to a domain, and Chromium refuses an IP address as one, so this file talks to the stack as
// localhost whatever address the other files use. The API allows exactly that origin (APP_WEBAUTHN_ORIGINS).
test.use({
  baseURL: (process.env['BASE_URL'] ?? 'http://127.0.0.1:8090').replace('127.0.0.1', 'localhost'),
});

const NEW_PASSWORD = 'a-long-enough-password';

async function toPassword(page: Page, email: string): Promise<void> {
  await page.goto('/login');
  await page.getByTestId('email').fill(email);
  await page.getByTestId('login-continue').click();
  await expect(page.getByTestId('password')).toBeFocused();
}

async function signOut(page: Page): Promise<void> {
  await page.getByTestId('user-menu').click();
  await page.getByTestId('logout').click();
  await expect(page).toHaveURL(/\/login$/);
}

test('the address comes first, then the password for any address, and the address can be changed', async ({
  page,
}) => {
  await page.goto('/login');
  await expect(page.getByTestId('email')).toHaveAttribute('autocomplete', 'username webauthn');
  await expect(page.getByTestId('password')).toHaveCount(0);

  // No account holds this address, and the page goes on just the same: it never says which addresses are known.
  await toPassword(page, `nobody-${Date.now()}@twes.local`);
  await expect(page.getByTestId('login-address')).toContainText('nobody-');
  expect(await wcagViolations(page), 'login, password step').toEqual([]);
  await page.getByTestId('password').fill('not-a-password');
  await page.getByTestId('submit').click();
  await expect(page.getByTestId('login-error')).toBeVisible();
  await expect(page.getByTestId('password')).toHaveValue('');

  await page.getByTestId('login-change-email').click();
  await expect(page.getByTestId('email')).toBeFocused();
  await expect(page.getByTestId('password')).toHaveCount(0);
});

test(
  'on a phone, the address and the password are two steps of their own',
  { tag: '@phone' },
  async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/login');
    await page.getByTestId('email').fill(`phone-${Date.now()}@twes.local`);
    await page.getByTestId('login-continue').click();
    await expect(page.getByTestId('password')).toBeVisible();
    await expect(page.getByTestId('login-address')).toContainText('phone-');
    expect(await wcagViolations(page), 'login on a phone, password step').toEqual([]);
    await page.getByTestId('login-change-email').click();
    await expect(page.getByTestId('login-continue')).toBeVisible();
  },
);

test('an account switched on signs in with its passkey alone, and with nothing once switched off', async ({
  page,
  request,
}) => {
  test.setTimeout(210_000);
  await addVirtualAuthenticator(page);
  const invited = `passkey-alone-${Date.now()}@twes.local`;

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
  await page.getByTestId('invitation-name').fill('Passkey Alone');
  await page.getByTestId('invitation-password').fill(NEW_PASSWORD);
  await page.getByTestId('invitation-submit').click();
  await expect(page).toHaveURL(/\/login$/);

  // The authenticator app first, then a passkey: the switch is offered only beside both.
  await toPassword(page, invited);
  await page.getByTestId('password').fill(NEW_PASSWORD);
  await page.getByTestId('submit').click();
  await expect(page.getByTestId('greeting')).toContainText('Passkey Alone');
  await page.getByTestId('user-menu').click();
  await page.getByTestId('two-factor-link').click();
  await proveIfAsked(page, NEW_PASSWORD, page.getByTestId('two-factor-secret'));
  const secret = (await page.getByTestId('two-factor-secret').innerText()).replace(/\s/g, '');
  await expect(page.getByTestId('two-factor-passkey-sign-in-needs')).toBeVisible();
  await page.getByTestId('two-factor-code').fill(totp(secret));
  await page.getByTestId('two-factor-submit').click();
  await expect(page.getByTestId('two-factor-recovery-codes').locator('li')).toHaveCount(10);
  await page.getByTestId('two-factor-continue').click();
  await expect(page.getByTestId('greeting')).toBeVisible();
  await page.getByTestId('user-menu').click();
  await page.getByTestId('two-factor-link').click();
  await page.getByTestId('two-factor-passkey-name').fill('This laptop');
  await page.getByTestId('two-factor-passkey-add').click();
  await proveIfAsked(page, NEW_PASSWORD, page.getByTestId('two-factor-passkey-name-label'));
  await expect(page.getByTestId('two-factor-passkey-name-label')).toHaveText(['This laptop']);

  await expect(page.getByTestId('two-factor-passkey-sign-in-state')).toBeVisible();
  await page.getByTestId('two-factor-passkey-sign-in-on').click();
  await proveIfAsked(page, NEW_PASSWORD, toast(page));
  await expect(toast(page)).toBeVisible();
  await expect(page.getByTestId('two-factor-passkey-sign-in-off')).toBeVisible();
  expect(await wcagViolations(page), 'two-factor, passkey alone on').toEqual([]);
  await page.screenshot({
    path: test.info().outputPath('two-factor-passkey-alone.png'),
    fullPage: true,
  });

  // Signed out, the passkey alone signs in: no address, no password, no code.
  await page.goto('/');
  await signOut(page);
  await expect(page.getByTestId('login-passkey')).toBeVisible();
  expect(await wcagViolations(page), 'login, address step with a passkey').toEqual([]);
  await page.screenshot({
    path: test.info().outputPath('login-passkey-alone.png'),
    fullPage: true,
  });
  await page.getByTestId('login-passkey').click();
  await expect(page.getByTestId('greeting')).toContainText('Passkey Alone');

  // The address field offers the same passkey, and picking it signs in by itself: nothing is clicked.
  await signOut(page);
  await passkeysUnderTheField(page, true);
  await page.reload();
  await expect(page.getByTestId('greeting')).toContainText('Passkey Alone');
  await expect(page).not.toHaveURL(/\/login$/);
  await passkeysUnderTheField(page, false);

  // Switched off, the same passkey is refused, and the password still leads to the code.
  await page.getByTestId('user-menu').click();
  await page.getByTestId('two-factor-link').click();
  await page.getByTestId('two-factor-passkey-sign-in-off').click();
  await expect(page.getByTestId('two-factor-passkey-sign-in-on')).toBeVisible();
  await page.goto('/');
  await signOut(page);
  await page.getByTestId('login-passkey').click();
  await expect(page.getByTestId('login-error')).toBeVisible();
  await expect(page).toHaveURL(/\/login$/);
  await toPassword(page, invited);
  await page.getByTestId('password').fill(NEW_PASSWORD);
  await page.getByTestId('submit').click();
  await expect(page.getByTestId('mfa-form')).toBeVisible();
});
