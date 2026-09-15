// SPDX-License-Identifier: AGPL-3.0-or-later
import { Browser, expect, Page, test } from '@playwright/test';
import { mailTo, signupTokenFor } from './mailpit';

// G1d through the real bundle, nginx, FrankenPHP, PostgreSQL and Mailpit: an operator opens signup, somebody signs up
// from the login page, their company waits, the operator approves it, and the owner gets in. The operator closes
// signup again at the end, because one database is shared by the whole suite and signup is closed by default.
//
// Each run creates one account and one company with unique names; neither can be deleted through the API. The
// per-client signup limit is five requests an hour, which a local rerun loop can reach.
const OPERATOR_EMAIL = process.env['E2E_EMAIL'] ?? 'operator@twes.local';
const OPERATOR_PASSWORD = process.env['E2E_PASSWORD'] ?? 'twes-operator-dev';
const SHOTS = 'test-results/screenshots';

async function signIn(page: Page, email: string, password: string): Promise<void> {
  await page.goto('/login');
  await page.getByTestId('email').fill(email);
  await page.getByTestId('password').fill(password);
  await page.getByTestId('submit').click();
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
    expect((await saved).ok()).toBe(true);
  }
  await expect(toggle).toHaveAttribute('aria-checked', String(on));
}

async function newPage(browser: Browser): Promise<Page> {
  return (await browser.newContext()).newPage();
}

test('somebody signs up, their company waits, an operator approves it, and the owner gets in', async ({
  page,
  browser,
  request,
}) => {
  const stamp = Date.now();
  const email = `signup-${stamp}@example.test`;
  const password = `Twes-signup-${stamp}!`;
  const company = `Signup Co ${stamp}`;

  // The operator opens signup, with approval required.
  await signIn(page, OPERATOR_EMAIL, OPERATOR_PASSWORD);
  await expect(page).toHaveURL(/\/$/);
  await page.getByTestId('hello-platform-link').click();
  await expect(page).toHaveURL(/\/platform$/);
  await setSwitch(page, 'platform-signup-enabled', true);
  await setSwitch(page, 'platform-approval-required', true);

  try {
    // Somebody finds the offer on the login page and asks for a link.
    const visitor = await newPage(browser);
    await visitor.goto('/login');
    await visitor.getByTestId('login-signup').click();
    await expect(visitor).toHaveURL(/\/signup$/);
    await visitor.getByTestId('signup-email').fill(email);
    await visitor.screenshot({ path: `${SHOTS}/signup-1-request.png` });
    await visitor.getByTestId('signup-submit').click();
    await expect(visitor.getByTestId('signup-sent')).toBeVisible();

    // The link from the mail finishes it: the account, and the company it owns.
    const token = await signupTokenFor(request, email);
    await visitor.goto(`/signup/${token}`);
    await expect(visitor.getByTestId('signup-finish-email')).toHaveText(email);
    await visitor.getByTestId('signup-name').fill('Nadia Signup');
    await visitor.getByTestId('signup-password').fill(password);
    await visitor.getByTestId('signup-company').fill(company);
    await visitor.getByTestId('signup-country').click();
    await visitor.getByTestId('signup-country-TN').click();
    await visitor.screenshot({ path: `${SHOTS}/signup-2-finish.png` });
    await visitor.getByTestId('signup-finish-submit').click();
    await expect(visitor.getByTestId('signup-completed-pending')).toContainText(company);

    // Signed in, the owner is told the company waits, and nothing else opens.
    await signIn(visitor, email, password);
    await expect(visitor).toHaveURL(/\/awaiting-approval$/);
    await expect(visitor.getByTestId('awaiting-pending')).toContainText(company);
    await visitor.screenshot({ path: `${SHOTS}/signup-3-awaiting.png` });

    // The operator sees it waiting and approves it.
    await page.reload();
    const line = page.getByTestId(`waiting-${company}`);
    await expect(line).toContainText(email);
    await page.screenshot({ path: `${SHOTS}/signup-4-platform.png` });
    await page.getByTestId(`approve-${company}`).click();
    await expect(line).toHaveCount(0);

    // The owner is told, and is let in.
    const approval = await mailTo(request, email, (subject) => subject.includes(company));
    expect(approval).toContain('/login');
    await visitor.goto('/');
    await expect(visitor).toHaveURL(/\/$/);
    await expect(visitor.getByTestId('greeting')).toContainText('Nadia Signup');
    await visitor.screenshot({ path: `${SHOTS}/signup-5-approved.png` });
  } finally {
    await page.goto('/platform');
    await setSwitch(page, 'platform-signup-enabled', false);
  }
});
