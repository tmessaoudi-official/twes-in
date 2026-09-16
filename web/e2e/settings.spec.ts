// SPDX-License-Identifier: AGPL-3.0-or-later
import AxeBuilder from '@axe-core/playwright';
import { expect, type Page, test } from '@playwright/test';
import { signIn } from './session';
import { toast } from './toast';

// G3b through the real stack: the seeded company's owner sets a business default on the settings page, it survives
// a reload, and a reset returns it to the declared default. One database is shared by the whole suite, so the
// company's value is forgotten before and after.
const CSRF = '0123456789abcdef0123456789abcdef';
const TERMS = 'document.payment_terms_days';

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

    // The suite's accessibility bar is WCAG 2.1 AA, as in accessibility.spec.ts.
    const axe = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();
    expect(axe.violations.map((violation) => violation.id)).toEqual([]);

    await terms.fill('45');
    await page.getByTestId('settings-save').click();
    await expect(toast(page)).toContainText('Les paramètres ont été enregistrés.');

    await page.reload();
    await expect(terms).toHaveValue('45');

    await page.getByTestId(`settings-reset-${TERMS}`).click();
    await expect(terms).toHaveValue('30');
    await expect(page.getByTestId(`settings-override-${TERMS}`)).toHaveCount(0);
  } finally {
    await forgetCompanyTerms(page);
  }
});
