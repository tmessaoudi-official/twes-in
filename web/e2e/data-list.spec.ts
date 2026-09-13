// SPDX-License-Identifier: AGPL-3.0-or-later
import AxeBuilder from '@axe-core/playwright';
import { expect, type Page, test } from '@playwright/test';

// G2b: presentation preferences survive a reload through the real bundle (browser storage until G3b), and the
// list chrome every screen shares stays accessible with its column chooser open, at desktop and phone width.
// Each test runs in a fresh browser context, so nothing it chooses leaks into another scenario.
const EMAIL = process.env['E2E_EMAIL'] ?? 'operator@twes.local';
const PASSWORD = process.env['E2E_PASSWORD'] ?? 'twes-operator-dev';

async function signIn(page: Page): Promise<void> {
  await page.goto('/login');
  await page.getByTestId('email').fill(EMAIL);
  await page.getByTestId('password').fill(PASSWORD);
  await page.getByTestId('submit').click();
  await expect(page).toHaveURL(/\/$/);
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
