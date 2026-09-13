// SPDX-License-Identifier: AGPL-3.0-or-later
import AxeBuilder from '@axe-core/playwright';
import { expect, type Page, test } from '@playwright/test';
import { forgetPresentationChoices } from './presentation';

// G2b and G3b: presentation preferences survive a reload, and a fresh browser, through the API's presentation
// chain, and the list chrome every screen shares stays accessible with its column chooser open, at desktop and
// phone width. The choices live in the shared database, so each scenario starts by forgetting the operator's own.
const EMAIL = process.env['E2E_EMAIL'] ?? 'operator@twes.local';
const PASSWORD = process.env['E2E_PASSWORD'] ?? 'twes-operator-dev';

async function logIn(page: Page): Promise<void> {
  await page.goto('/login');
  await page.getByTestId('email').fill(EMAIL);
  await page.getByTestId('password').fill(PASSWORD);
  await page.getByTestId('submit').click();
  await expect(page).toHaveURL(/\/$/);
}

async function signIn(page: Page): Promise<void> {
  await logIn(page);
  await forgetPresentationChoices(page);
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

async function openMembers(page: Page): Promise<void> {
  await page.goto('/members');
  await expect(page.getByTestId('members-title')).toBeVisible();
  await expect(page.getByTestId(`member-${EMAIL}`)).toBeVisible();
}

test('a column hidden from the chooser stays hidden after a reload, until the columns are reset', async ({
  page,
}) => {
  await signIn(page);
  await openMembers(page);
  await expect(page.getByTestId('list-header-email')).toBeVisible();

  await page.getByTestId('list-columns').click();
  await expectAccessible(page, 'members, column chooser open');
  await page.getByTestId('list-column-toggle-email').click();
  await expect(page.getByTestId('list-header-email')).toHaveCount(0);

  await page.reload();
  await expect(page.getByTestId(`member-${EMAIL}`)).toBeVisible();
  await expect(page.getByTestId('list-header-role')).toBeVisible();
  await expect(page.getByTestId('list-header-email')).toHaveCount(0);

  await page.getByTestId('list-columns').click();
  await page.getByTestId('list-columns-reset').click();
  await expect(page.getByTestId('list-header-email')).toBeVisible();
});

test('a column moves without dragging, and the order survives a reload', async ({ page }) => {
  await signIn(page);
  await openMembers(page);

  await page.getByTestId('list-columns').click();
  await page.getByTestId('list-column-up-role').click();
  const order = () =>
    page
      .locator('[data-testid^="list-header-"]')
      .evaluateAll((cells) => cells.map((cell) => cell.getAttribute('data-testid')));
  await expect.poll(order).toEqual(['list-header-name', 'list-header-role', 'list-header-email']);

  await page.reload();
  await expect(page.getByTestId(`member-${EMAIL}`)).toBeVisible();
  await expect.poll(order).toEqual(['list-header-name', 'list-header-role', 'list-header-email']);
});

test('the dark scheme survives a reload', async ({ page }) => {
  await signIn(page);
  await page.getByTestId('user-menu').click();
  await page.getByTestId('theme-toggle').click();
  await expect(page.locator('html')).toHaveClass(/theme-dark/);

  await page.reload();
  await expect(page.getByTestId('greeting')).toBeVisible();
  await expect(page.locator('html')).toHaveClass(/theme-dark/);
});

test('a saved view brings back its filters and columns after a reload', async ({ page }) => {
  await signIn(page);
  await openMembers(page);

  await page.getByTestId('list-filter').fill('operator');
  await page.getByTestId('list-facet-role').selectOption('owner');
  await page.getByTestId('list-columns').click();
  await page.getByTestId('list-column-toggle-email').click();
  await page.getByTestId('list-views').click();
  await page.getByTestId('list-view-name').fill('Owners');
  await page.getByTestId('list-view-save').click();
  const saved = page.locator('[data-testid^="list-view-apply-"]', { hasText: 'Owners' });
  await expect(saved).toHaveAttribute('aria-pressed', 'true');
  await expectAccessible(page, 'members, saved views open');

  await page.reload();
  await expect(page.getByTestId(`member-${EMAIL}`)).toBeVisible();
  await expect(page.getByTestId('list-filter')).toHaveValue('');
  await page.getByTestId('list-columns').click();
  await page.getByTestId('list-columns-reset').click();
  await expect(page.getByTestId('list-header-email')).toBeVisible();

  await page.getByTestId('list-views').click();
  await saved.click();
  await expect(page.getByTestId('list-header-email')).toHaveCount(0);
  await expect(page.getByTestId('list-filter')).toHaveValue('operator');
  await expect(page.getByTestId('list-facet-role')).toHaveValue('owner');
  await expect(page.getByTestId(`member-${EMAIL}`)).toBeVisible();
});

test('compact density follows the person into a fresh browser', async ({ page, browser }) => {
  await signIn(page);
  await expect(page.locator('html')).not.toHaveClass(/density-compact/);
  await page.getByTestId('user-menu').click();
  const saved = page.waitForResponse(
    (response) =>
      response.url().includes('/settings/presentation.density') &&
      response.request().method() === 'PUT',
  );
  await page.getByTestId('density-toggle').click();
  expect((await saved).status()).toBe(200);
  await expect(page.locator('html')).toHaveClass(/density-compact/);

  // A second browser context shares no storage with the first: only the API can carry the choice over.
  const elsewhere = await browser.newContext({ baseURL: test.info().project.use.baseURL });
  try {
    const other = await elsewhere.newPage();
    await logIn(other);
    await expect(other.getByTestId('greeting')).toBeVisible();
    await expect(other.locator('html')).toHaveClass(/density-compact/);
  } finally {
    await elsewhere.close();
  }
});

test('at phone width the members list and its chooser are accessible and fit the screen', async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await signIn(page);
  await openMembers(page);

  await page.getByTestId('list-columns').click();
  await expect(page.getByTestId('list-column-toggle-role')).toBeVisible();
  await expectAccessible(page, 'members, phone, chooser open');
  const overflow = await page.evaluate(
    () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
  );
  expect(overflow, 'the page itself must not scroll sideways').toBeLessThanOrEqual(0);
});
