// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { invitationTokenFor } from './mailpit';
import { signIn } from './session';

// The notification centre through the whole stack (docs/SPEC.md § 7, 2026-09-13): accepting an invitation
// publishes to the company channel, the API keeps a row for every member and pushes through Centrifugo, and the
// operator's open page hears it over the WebSocket that nginx proxies, with no reload. The row outlives the page.

async function unreadOf(page: Page): Promise<number> {
  return Number(await page.getByTestId('notification-bell').getAttribute('data-unread'));
}

test('a member joining reaches the open page live, and stays in the centre after a reload', async ({
  page,
  browser,
  request,
}) => {
  const invited = `joiner-${Date.now()}@twes.local`;
  const name = `Joiner ${Date.now()}`;

  await signIn(page);
  await page.getByTestId('settings-gear').click();
  await page.getByTestId('nav-members').click();
  await expect(page).toHaveURL(/\/members$/);
  await expect(page.getByTestId('notification-bell')).toHaveAttribute('data-unread', /^\d+$/);
  const before = await unreadOf(page);

  await page.getByTestId('member-email').fill(invited);
  await page.getByTestId('member-add').click();
  await expect(page.getByTestId('members-added')).toContainText('invitation');
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
