// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test';
import { invitationTokenFor, mailTo } from './mailpit';
import { signIn as signInAsOperator } from './session';
import { toast } from './toast';

// A forgotten password through the real stack: the request only queues the ask, and the worker looks the account up,
// makes the link and mails it (docs/SPEC.md § 7, 2026-10-06 02:42), so this passes only with the worker running. The
// account is one of the run's own, never the operator's, which every other spec signs in with.
const FIRST = 'a-long-enough-password';
const NEXT = 'another-long-enough-password';

test('a forgotten password comes back through the mailed link, made by the worker', async ({
  page,
  request,
}) => {
  const address = `forgot-${Date.now()}@twes.local`;
  await signInAsOperator(page);
  await page.getByTestId('nav-settings').click();
  await page.getByTestId('nav-members').click();
  await page.getByTestId('member-email').fill(address);
  await page.getByTestId('member-add').click();
  await expect(toast(page)).toContainText('invitation');
  const invitation = await invitationTokenFor(request, address);
  await page.context().clearCookies();
  await page.goto(`/invitations/${invitation}`);
  await page.getByTestId('invitation-name').fill('Forgetful Person');
  await page.getByTestId('invitation-password').fill(FIRST);
  await page.getByTestId('invitation-submit').click();
  await expect(page).toHaveURL(/\/login$/);

  await page.goto('/forgot-password');
  await page.getByTestId('forgot-email').fill(address);
  await page.getByTestId('forgot-submit').click();
  await expect(page.getByTestId('forgot-sent')).toBeVisible();

  const mail = await mailTo(request, address, (subject) => subject.includes('mot de passe'));
  const link = /\/reset-password\/([0-9a-f]{64})/.exec(mail);
  expect(link, 'the mail carries the link').not.toBeNull();
  await page.goto(`/reset-password/${link![1]}`);
  await page.getByTestId('reset-password').fill(NEXT);
  await page.getByTestId('reset-again').fill(NEXT);
  await page.getByTestId('reset-submit').click();
  await expect(page.getByTestId('reset-done')).toBeVisible();

  await page.goto('/login');
  await page.getByTestId('email').fill(address);
  await page.getByTestId('password').fill(NEXT);
  await page.getByTestId('submit').click();
  await expect(page).toHaveURL(/\/$/);
  await expect(page.getByTestId('greeting')).toContainText('Forgetful Person');
});
