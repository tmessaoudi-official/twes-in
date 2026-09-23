// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test';
import { invitationTokenFor } from './mailpit';
import { inACompany, signIn } from './session';
import { toast } from './toast';

const CSRF = '0123456789abcdef0123456789abcdef';

// The notification centre through the whole stack (docs/SPEC.md § 7, 2026-09-13): accepting an invitation
// publishes to the company channel, the API keeps a row for every member and pushes through Centrifugo, and the
// operator's open page hears it over the WebSocket that nginx proxies, with no reload. The row outlives the page.

test('a member joining reaches the open page live, and stays in the centre after a reload', async ({
  page,
  browser,
  request,
}) => {
  const invited = `joiner-${Date.now()}@twes.local`;
  const name = `Joiner ${Date.now()}`;

  await signIn(page);
  await inACompany(page, CSRF);
  await page.goto('/');
  await page.getByTestId('nav-settings').click();
  await page.getByTestId('nav-members').click();
  await expect(page).toHaveURL(/\/members$/);
  // What the API holds, and the bell showing it: the bell reads 0 until its first answer arrives, and a count taken
  // from it before then is short by whatever earlier scenarios left unread (CI run 35898253274: 0, then 5).
  const before = await page.evaluate(
    async () =>
      ((await (await fetch('/api/me/notifications')).json()) as { unread: number }).unread,
  );
  await expect(page.getByTestId('notification-bell')).toHaveAttribute(
    'data-unread',
    String(before),
  );

  await page.getByTestId('member-email').fill(invited);
  await page.getByTestId('member-add').click();
  await expect(toast(page)).toContainText('invitation');
  const token = await invitationTokenFor(request, invited);

  // The invited person is somebody else, in a browser of their own.
  const stranger = await browser.newContext();
  const theirPage = await stranger.newPage();
  await theirPage.goto(`/invitations/${token}`);
  await theirPage.getByTestId('invitation-name').fill(name);
  await theirPage.getByTestId('invitation-password').fill('a-long-enough-password');
  await theirPage.getByTestId('invitation-submit').click();
  await expect(theirPage).toHaveURL(/\/login$/);
  await stranger.close();

  // No reload: only the push can have moved the count.
  await expect(page.getByTestId('notification-bell')).toHaveAttribute(
    'data-unread',
    String(before + 1),
  );

  await page.reload();
  await expect(page.getByTestId('notification-bell')).toHaveAttribute(
    'data-unread',
    String(before + 1),
  );
  await page.getByTestId('notification-bell').click();
  await expect(page.getByTestId('notification-text').first()).toContainText(name);

  await page.getByTestId('notifications-read-all').click();
  await expect(page.getByTestId('notification-bell')).toHaveAttribute('data-unread', '0');
});
