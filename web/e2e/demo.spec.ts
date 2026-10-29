// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, Page, test } from '@playwright/test';
import { OPERATOR_PASSWORD, leaveWhenAsked, signIn as signInAsOperator } from './session';
import { proveIfHeldBack } from './step-up';
import { toast } from './toast';

// « Essayer la démo » through the real bundle, nginx, FrankenPHP, PostgreSQL and the demo worker: a visitor with no
// account takes a sandbox of their own, works in it, reads the mails it kept, is refused the accountant's files in one
// toast and signs out; the operators switch the
// demo off, and the sign-in page stops offering it, then on again, as every installation starts.
//
// A fresh stack has no sandbox ready until the worker's first tend or the first visitor's claim queues one, and a
// build takes a while on a loaded machine: a claim answered « la démo se prépare » is tried again after the 30 s its
// Retry-After asks. Every attempt counts against the entry limit (10 an hour per address, busy answers included), so
// eight attempts at most fit the wait, and a local rerun loop can reach the limit.
const BUILD_WAIT = 240_000;
const RETRY_AFTER = 30_000;

/** Signs in as the demo's visitor for that country, trying again while its sandbox is still being built. */
async function enterDemo(page: Page, country: string): Promise<void> {
  await page.goto('/login');
  const offer = page.getByTestId(`login-demo-${country}`);
  await expect(async () => {
    if (!/\/login$/.test(page.url())) return;
    await expect(offer).toBeEnabled();
    const claim = page.waitForResponse(
      (response) =>
        response.url().endsWith('/api/auth/demo') && response.request().method() === 'POST',
    );
    await offer.click();
    expect((await claim).status(), 'a ready sandbox is claimed').toBe(200);
  }).toPass({ timeout: BUILD_WAIT, intervals: [RETRY_AFTER] });
  await expect(page).toHaveURL(/\/$/);
}

/** Sets a platform switch through the page and waits until the page shows the platform holds it. */
async function setSwitch(page: Page, testId: string, on: boolean): Promise<void> {
  const toggle = page.getByTestId(testId).getByRole('switch');
  await expect(toggle).toBeEnabled();
  if ((await toggle.getAttribute('aria-checked')) !== String(on)) {
    const saved = page.waitForResponse(
      (response) =>
        response.url().includes('/api/platform/settings/') && response.request().method() === 'PUT',
    );
    await toggle.click();
    expect((await proveIfHeldBack(page, OPERATOR_PASSWORD, saved)).ok()).toBe(true);
  }
  await expect(toggle).toHaveAttribute('aria-checked', String(on));
}

test('a visitor tries the demo, works in a sandbox of their own, reads its mails, is refused the books once and signs out', async ({
  page,
}) => {
  test.setTimeout(BUILD_WAIT + 120_000);
  leaveWhenAsked(page);

  await enterDemo(page, 'TN');

  // Every page says it is a demo, and the company is the demo's, its months of invoices there to open.
  await expect(page.getByTestId('demo-banner')).toBeVisible();
  await page.goto('/invoices');
  await expect(page.getByText(/^FAC-\d{4}-\d{2}-\d{5}$/).first()).toBeVisible();

  // The mails the demo kept instead of sending them have a page of their own, reached from the banner.
  await page.getByTestId('demo-banner-mails').click();
  await expect(page.getByTestId('demo-mails-page')).toBeVisible();
  await expect(
    page.getByTestId('demo-mails-none').or(page.getByTestId('demo-mails-list')),
  ).toBeVisible();

  // The accountant's files stand for real books, which a demo never hands out: asked for, the refusal is said once, in
  // the toast, and the page adds no failure of its own.
  await page.goto('/accounting-export');
  await page.getByTestId('accounting-export-from').fill('2026-01-01');
  await page.getByTestId('accounting-export-to').fill('2026-12-31');
  await page.getByTestId('accounting-export-sales-journal').click();
  await page.getByTestId('accounting-export-sales-journal-csv').click();
  await expect(toast(page)).toContainText('Non disponible dans la démo.');
  await expect(toast(page)).toHaveCount(1);
  await expect(page.getByText('Le fichier n’a pas pu être préparé')).toHaveCount(0);

  await page.getByTestId('user-menu').click();
  await page.getByTestId('logout').click();
  await expect(page).toHaveURL(/\/login$/);
  await page.goto('/invoices');
  await expect(page).toHaveURL(/\/login/);
});

test('the operators switch the demo off, and the sign-in page stops offering it, then on again', async ({
  page,
  browser,
}) => {
  await signInAsOperator(page);
  await page.goto('/platform');
  const visitor = await (await browser.newContext()).newPage();
  try {
    await setSwitch(page, 'platform-demo-enabled', false);
    const asked = visitor.waitForResponse(
      (response) =>
        response.url().endsWith('/api/auth/demo') && response.request().method() === 'GET',
    );
    await visitor.goto('/login');
    expect(await (await asked).json()).toMatchObject({ enabled: false });
    await expect(visitor.getByTestId('login-continue')).toBeVisible();
    await expect(visitor.getByTestId('login-demo')).toHaveCount(0);
  } finally {
    // One database is shared by the whole suite, and the demo is on unless an operator says otherwise.
    await setSwitch(page, 'platform-demo-enabled', true);
  }
  await visitor.goto('/login');
  await expect(visitor.getByTestId('login-demo-TN')).toBeVisible();
  await visitor.context().close();
});
