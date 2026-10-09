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
    // The default unit is chosen by its name among the company's units, never typed as its code (« C62 »).
    await expect(page.getByTestId('field-article__default_unit')).toContainText('Unité');
    await expect(page.getByTestId('field-article__default_unit')).not.toContainText('C62');

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

test('the fields of one row line up, whatever the length of their labels', async ({ page }) => {
  // Audit 2026-10-06 V-15: a sentence as a label pushed its input 40 px below its neighbour's, and two checkboxes
  // centred on texts of one and two lines did not line up.
  await signIn(page);
  await page.goto('/settings');
  await expect(page.getByTestId('field-document__payment_terms_days')).toBeVisible();
  const top = (testId: string, inner: string) =>
    page
      .getByTestId(testId)
      .locator(inner)
      .first()
      .evaluate((element) => Math.round(element.getBoundingClientRect().top));
  const self = ':scope';
  // Two pairs that share a row: two fields whose labels run to a sentence, and two checkboxes.
  expect(await top('field-document__late_payment_rate', self)).toBe(
    await top('field-document__exemption_reference', self),
  );
  expect(await top('field-reminders__hour', self)).toBe(
    await top('field-quote__validity_days', self),
  );
  expect(await top('field-document__how_to_pay', '.mdc-checkbox__background')).toBe(
    await top('field-document__amount_in_words', '.mdc-checkbox__background'),
  );
  expect(await top('field-document__paid_stamp', '.mdc-checkbox__background')).toBe(
    await top('field-document__savings_line', '.mdc-checkbox__background'),
  );
  await expect(page.getByTestId('settings-title')).toHaveText('Valeurs par défaut');
});
