// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { wcagViolations } from './axe';
import { invitationTokenFor } from './mailpit';
import { signIn as signInAsOperator } from './session';
import { toast } from './toast';

// « Effacer des données » through the whole stack (docs/SPEC.md row 252): an owner proves who they are, counts what a
// part holds, erases it, sees the banner on every page and undoes it. It erases in a company of its own: in the shared
// Demo company it would take the plan away from every other spec running beside it. Each run leaves one company and
// one account behind: neither is deleted.
const PASSWORD = 'a-long-enough-password';
const CSRF = '0123456789abcdef0123456789abcdef';

/** Calls the API from inside the page, which is the only way the session cookie travels (SameSite=Strict). */
async function api(page: Page, method: string, path: string, body: unknown): Promise<unknown> {
  return page.evaluate(
    async ([csrf, verb, url, payload]) => {
      const response = await fetch(url as string, {
        method: verb as string,
        headers: { 'content-type': 'application/json', 'csrf-token': csrf as string },
        body: payload === null ? null : JSON.stringify(payload),
      });
      if (!response.ok) throw new Error(`${verb} ${url} answered ${response.status}`);
      return response.status === 204 ? null : response.json();
    },
    [CSRF, method, path, body] as const,
  );
}

test('an owner erases the stock plan, sees it waiting on every page, and undoes it', async ({
  page,
  browser,
  request,
}) => {
  test.setTimeout(180_000);
  const stamp = Date.now();
  const owner = `eraser-${stamp}@twes.local`;

  await signInAsOperator(page);
  const company = (await api(page, 'POST', '/api/companies', {
    name: `Effaçable ${stamp}`,
    countryCode: 'TN',
    currency: 'TND',
    locale: 'fr',
    timezone: 'Africa/Tunis',
  })) as { id: string };
  await api(page, 'POST', `/api/platform/companies/${company.id}/owners`, { email: owner });
  await api(page, 'POST', `/api/platform/companies/${company.id}/approve`, {});

  const theirs = await browser.newContext();
  const theirPage = await theirs.newPage();
  await theirPage.goto(`/invitations/${await invitationTokenFor(request, owner)}`);
  await theirPage.getByTestId('invitation-name').fill('Effaçable Owner');
  await theirPage.getByTestId('invitation-password').fill(PASSWORD);
  await theirPage.getByTestId('invitation-submit').click();
  await expect(theirPage).toHaveURL(/\/login$/);
  await theirPage.getByTestId('email').fill(owner);
  await theirPage.getByTestId('password').fill(PASSWORD);
  await theirPage.getByTestId('submit').click();
  await expect(theirPage).toHaveURL(/\/$/);

  // One floor on the plan, so the part has something to count and something to bring back.
  const base = `/api/companies/${company.id}`;
  for (const key of ['products', 'inventory']) {
    await api(theirPage, 'PUT', `${base}/modules/${key}`, { enabled: true });
  }
  const options = (await api(theirPage, 'GET', `${base}/stock-options`, null)) as {
    establishments: { id: string }[];
  };
  const establishment = options.establishments[0];
  if (!establishment) throw new Error('the new company has no establishment');
  await api(theirPage, 'POST', `${base}/stock-floors`, {
    establishmentId: establishment.id,
    name: 'Rez-de-chaussée',
    level: 0,
    widthMetres: '24',
    depthMetres: '15',
  });

  // Reached from the settings, under « Données », and closed until the password is given again.
  await theirPage.getByTestId('nav-settings').click();
  await theirPage.getByTestId('nav-data-erasure').click();
  await expect(theirPage).toHaveURL(/\/company\/data-erasure$/);
  await expect(theirPage.getByTestId('erasure-locked')).toBeVisible();
  await theirPage.getByTestId('erasure-confirm').click();
  await theirPage.getByTestId('step-up-password').fill(PASSWORD);
  await theirPage.getByTestId('step-up-confirm').click();
  await expect(theirPage.getByTestId('erasure-confirmed')).toBeVisible();

  const stockMap = theirPage.getByTestId('erasure-part-stock_map');
  await expect(stockMap).toContainText('1 étage');
  expect(await wcagViolations(theirPage)).toEqual([]);
  await theirPage.screenshot({ path: test.info().outputPath('data-erasure-page.png') });

  await stockMap.locator('input[type="checkbox"]').check();
  await theirPage.getByTestId('erasure-review').click();
  await expect(theirPage.getByTestId('erasure-preview-lines')).toContainText('Plan du stock');
  await theirPage.getByTestId('erasure-preview-erase').click();
  await expect(toast(theirPage)).toContainText('Effacé');

  // The banner says it waits and offers the undo; the page waits for it before another erasure.
  const banner = theirPage.getByTestId('erasure-banner');
  await expect(banner).toBeVisible();
  // Both take the top of the window: the toast stands below the banner, never over its undo.
  const bannerBox = await banner.boundingBox();
  if (!bannerBox) throw new Error('the banner has no box');
  await expect
    .poll(async () => (await toast(theirPage).boundingBox())?.y ?? -1)
    .toBeGreaterThanOrEqual(bannerBox.y + bannerBox.height);
  await expect(theirPage.getByTestId('erasure-waiting')).toBeVisible();
  const floors = async (): Promise<unknown[]> =>
    (await api(theirPage, 'GET', `${base}/stock-floors`, null)) as unknown[];
  expect(await floors()).toEqual([]);
  expect(await wcagViolations(theirPage)).toEqual([]);
  await theirPage.screenshot({ path: test.info().outputPath('data-erasure-banner.png') });

  await theirPage.getByTestId('erasure-undo').click();
  await expect(toast(theirPage)).toContainText('tout est revenu');
  await expect(banner).toBeHidden();
  expect(await floors()).toHaveLength(1);
  await theirs.close();
});
