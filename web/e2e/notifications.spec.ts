// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test';
import { invitationTokenFor, mailTo } from './mailpit';
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

// « Mon compte › Notifications »: a kind muted in one company is still told and listed, but the bell no longer counts
// it; switched back on, the bell counts it again. The operator's own choice is put back whatever happens, since the
// whole suite shares one database.
test('a kind muted in « Mon compte » is still listed, and the bell no longer counts it', async ({
  page,
  browser,
  request,
}) => {
  await signIn(page);
  await inACompany(page, CSRF);
  const companyId = await page.evaluate(
    async () =>
      ((await (await fetch('/api/auth/me')).json()) as { company: { id: string } }).company.id,
  );
  const unread = () =>
    page.evaluate(
      async () =>
        ((await (await fetch('/api/me/notifications')).json()) as { unread: number }).unread,
    );

  await page.goto('/account?tab=notifications');
  const bellOf = () =>
    page.getByTestId(`notification-${companyId}-invitation.accepted-bell`).getByRole('switch');
  const ring = bellOf();
  await expect(ring).toHaveAttribute('aria-checked', 'true');
  try {
    await ring.click();
    await expect(ring).toHaveAttribute('aria-checked', 'false');
    await page.reload();
    await expect(bellOf()).toHaveAttribute('aria-checked', 'false');
    const before = await unread();

    const invited = `muted-${Date.now()}@twes.local`;
    const name = `Muted ${Date.now()}`;
    const invitedStatus = await page.evaluate(
      async ([csrf, company, email]) =>
        (
          await fetch(`/api/companies/${company}/members`, {
            method: 'POST',
            headers: { 'content-type': 'application/json', 'csrf-token': csrf },
            body: JSON.stringify({ email, role: 'member' }),
          })
        ).status,
      [CSRF, companyId, invited] as const,
    );
    expect(invitedStatus).toBeLessThan(300);
    const token = await invitationTokenFor(request, invited);
    const stranger = await browser.newContext();
    const theirPage = await stranger.newPage();
    await theirPage.goto(`/invitations/${token}`);
    await theirPage.getByTestId('invitation-name').fill(name);
    await theirPage.getByTestId('invitation-password').fill('a-long-enough-password');
    await theirPage.getByTestId('invitation-submit').click();
    await expect(theirPage).toHaveURL(/\/login$/);
    await stranger.close();

    // Told and listed, not counted.
    await expect
      .poll(() =>
        page.evaluate(
          async (joiner) =>
            (
              (await (await fetch('/api/me/notifications')).json()) as {
                items: { payload: Record<string, unknown> }[];
              }
            ).items.some((item) => item.payload['display_name'] === joiner),
          name,
        ),
      )
      .toBe(true);
    expect(await unread()).toBe(before);

    await bellOf().click();
    await expect.poll(unread).toBe(before + 1);
  } finally {
    const status = await page.evaluate(
      async ([csrf, company]) =>
        (
          await fetch('/api/me/notification-preferences', {
            method: 'PUT',
            headers: { 'content-type': 'application/json', 'csrf-token': csrf },
            body: JSON.stringify({
              companyId: company,
              type: 'invitation.accepted',
              bell: true,
              email: true,
            }),
          })
        ).status,
      [CSRF, companyId] as const,
    );
    expect(status).toBe(204);
  }
});

// A kind told is also mailed, by the worker; its stop link opens a page that changes nothing until its button is
// pressed, then that kind's mail is off in that company only. The operator's choice is put back whatever happens.
test('a notification mail’s stop link turns that kind’s mail off from its page, with one button', async ({
  page,
  browser,
  request,
}) => {
  await signIn(page);
  await inACompany(page, CSRF);
  const companyId = await page.evaluate(
    async () =>
      ((await (await fetch('/api/auth/me')).json()) as { company: { id: string } }).company.id,
  );
  const mailOf = () =>
    page.evaluate(
      async ([company]) =>
        (
          (await (await fetch('/api/me/notification-preferences')).json()) as {
            preferences: {
              companyId: string | null;
              type: string;
              bell: boolean;
              email: boolean;
            }[];
          }
        ).preferences.find(
          (row) => row.companyId === company && row.type === 'invitation.accepted',
        ),
      [companyId] as const,
    );

  try {
    const invited = `mailed-${Date.now()}@twes.local`;
    const name = `Mailed ${Date.now()}`;
    const invitedStatus = await page.evaluate(
      async ([csrf, company, email]) =>
        (
          await fetch(`/api/companies/${company}/members`, {
            method: 'POST',
            headers: { 'content-type': 'application/json', 'csrf-token': csrf },
            body: JSON.stringify({ email, role: 'member' }),
          })
        ).status,
      [CSRF, companyId, invited] as const,
    );
    expect(invitedStatus).toBeLessThan(300);
    const token = await invitationTokenFor(request, invited);
    const stranger = await browser.newContext();
    const theirPage = await stranger.newPage();
    await theirPage.goto(`/invitations/${token}`);
    await theirPage.getByTestId('invitation-name').fill(name);
    await theirPage.getByTestId('invitation-password').fill('a-long-enough-password');
    await theirPage.getByTestId('invitation-submit').click();
    await expect(theirPage).toHaveURL(/\/login$/);
    await stranger.close();

    const mail = await mailTo(request, 'operator@twes.local', (subject) =>
      subject.startsWith('Nouveau membre'),
    );
    const link = /href="[^"]*(\/notifications\/stop\/[^"]+)"/.exec(mail);
    expect(link, 'the mail carries its stop link').not.toBeNull();

    await page.goto(link![1].replaceAll('&amp;', '&'));
    await expect(page.getByTestId('stop-mail-submit')).toBeVisible();
    expect((await mailOf())?.email, 'opening the link changes nothing').toBe(true);

    await page.getByTestId('stop-mail-submit').click();
    await expect(page.getByTestId('stop-mail-done')).toBeVisible();
    expect(await mailOf()).toMatchObject({ bell: true, email: false });
  } finally {
    const status = await page.evaluate(
      async ([csrf, company]) =>
        (
          await fetch('/api/me/notification-preferences', {
            method: 'PUT',
            headers: { 'content-type': 'application/json', 'csrf-token': csrf },
            body: JSON.stringify({
              companyId: company,
              type: 'invitation.accepted',
              bell: true,
              email: true,
            }),
          })
        ).status,
      [CSRF, companyId] as const,
    );
    expect(status).toBe(204);
  }
});
