// SPDX-License-Identifier: AGPL-3.0-or-later
import AxeBuilder from '@axe-core/playwright';
import { expect, type Page, test } from '@playwright/test';
import { forgetPresentationChoices } from './presentation';

// The design system's quality bar (docs/SPEC.md § 7, 2026-09-13): every screen passes axe's WCAG 2.1 A and AA
// rules in both colour schemes, the shell works at phone width, and the Content Security Policy is never
// violated while it is used. Screens are added here as they are built.
const EMAIL = process.env['E2E_EMAIL'] ?? 'operator@twes.local';
const PASSWORD = process.env['E2E_PASSWORD'] ?? 'twes-operator-dev';

async function signIn(page: Page): Promise<void> {
  await page.goto('/login');
  await page.getByTestId('email').fill(EMAIL);
  await page.getByTestId('password').fill(PASSWORD);
  await page.getByTestId('submit').click();
  await expect(page).toHaveURL(/\/$/);
  // The scheme a scenario checks must be the default, not a choice a previous scenario left in the database.
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

test('the login page is accessible', async ({ page }) => {
  await page.goto('/login');
  await expect(page.getByTestId('email')).toBeVisible();
  await expectAccessible(page, 'login');
});

test('the shell, the home page and the members page are accessible in light and dark', async ({
  page,
}) => {
  await signIn(page);
  await expect(page.getByTestId('greeting')).toBeVisible();
  await expectAccessible(page, 'home, light');

  await page.getByTestId('nav-members').click();
  await expect(page.getByTestId('members-title')).toBeVisible();
  await expectAccessible(page, 'members, light');

  await page.getByTestId('user-menu').click();
  await page.getByTestId('theme-toggle').click();
  await expect(page.locator('html')).toHaveClass(/theme-dark/);
  // axe must read the page, not the account menu's closing animation over it.
  await expect(page.locator('.mat-mdc-menu-panel')).toHaveCount(0);
  await expectAccessible(page, 'members, dark');
});

test('at phone width the navigation is a drawer behind the menu button', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await signIn(page);

  await expect(page.getByTestId('nav-members')).toBeHidden();
  await page.getByTestId('menu-toggle').click();
  await page.getByTestId('nav-members').click();
  await expect(page).toHaveURL(/\/members$/);
  await expect(page.getByTestId('nav-members')).toBeHidden();
});

test('the language switch translates the shell and the page', async ({ page }) => {
  await signIn(page);
  await expect(page.getByTestId('nav-home')).toContainText('Accueil');

  await page.getByTestId('user-menu').click();
  await page.getByTestId('language-en').click();

  await expect(page.getByTestId('nav-home')).toContainText('Home');
  await expect(page.getByTestId('greeting')).toContainText('Hello');
  await expect(page.locator('html')).toHaveAttribute('lang', 'en');
});

test('using the shell raises no Content Security Policy violation', async ({ page }) => {
  const violations: string[] = [];
  page.on('console', (message) => {
    if (/content security policy/i.test(message.text())) {
      violations.push(message.text());
    }
  });

  await signIn(page);
  await page.getByTestId('nav-members').click();
  await page.getByTestId('user-menu').click();
  await page.getByTestId('theme-toggle').click();
  await page.reload();
  await expect(page.getByTestId('members-title')).toBeVisible();

  expect(violations).toEqual([]);
});
