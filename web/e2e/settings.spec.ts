// SPDX-License-Identifier: AGPL-3.0-or-later
import AxeBuilder from '@axe-core/playwright';
import { expect, type Page, test } from '@playwright/test';

// G3b through the real stack: the seeded company's owner sets a business default on the settings page, it survives
// a reload, and a reset returns it to the declared default. One database is shared by the whole suite, so the
// company's value is forgotten before and after.
const EMAIL = process.env['E2E_EMAIL'] ?? 'operator@twes.local';
const PASSWORD = process.env['E2E_PASSWORD'] ?? 'twes-operator-dev';
const CSRF = '0123456789abcdef0123456789abcdef';
const TERMS = 'document.payment_terms_days';

async function signIn(page: Page): Promise<void> {
  await page.goto('/login');
  await page.getByTestId('email').fill(EMAIL);
  await page.getByTestId('password').fill(PASSWORD);
  await page.getByTestId('submit').click();
  await expect(page).toHaveURL(/\/$/);
}

async function forgetCompanyTerms(page: Page): Promise<void> {
  const status = await page.evaluate(
    async ([csrf, key]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const response = await fetch(
        `/api/companies/${me.company.id}/settings/${encodeURIComponent(key)}?level=company`,
        { method: 'DELETE', headers: { 'csrf-token': csrf } },
      );
      return response.status;
    },
    [CSRF, TERMS],
  );
  expect(status).toBe(204);
}

test("the owner sets the company's payment terms, which survive a reload until reset", async ({
  page,
}) => {
  await signIn(page);
  await forgetCompanyTerms(page);
  try {
    await page.goto('/settings');
    const terms = page.getByTestId('field-document__payment_terms_days');
    await expect(terms).toHaveValue('30');
    await expect(page.getByTestId('field-article__default_unit')).toHaveValue('C62');

    const axe = await new AxeBuilder({ page }).analyze();
    expect(axe.violations).toEqual([]);

    await terms.fill('45');
    await page.getByTestId('settings-save').click();
    await expect(page.getByTestId('settings-saved')).toBeVisible();

    await page.reload();
    await expect(terms).toHaveValue('45');

    await page.getByTestId(`settings-reset-${TERMS}`).click();
    await expect(terms).toHaveValue('30');
    await expect(page.getByTestId(`settings-override-${TERMS}`)).toHaveCount(0);
  } finally {
    await forgetCompanyTerms(page);
  }
});
