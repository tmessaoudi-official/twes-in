// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { inACompany, signIn } from './session';
import { toast } from './toast';
import { axeAtRest } from './axe';

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
    const axe = await (
      await axeAtRest(page)
    )
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();
    expect(axe.violations.map((violation) => violation.id)).toEqual([]);

    await terms.fill('45');
    await page.getByTestId('record-save').click();
    await expect(toast(page)).toContainText('Les paramètres ont été enregistrés.');

    await page.reload();
    await expect(terms).toHaveValue('45');

    await page.getByTestId(`settings-reset-${TERMS}`).click();
    await expect(terms).toHaveValue('30');
    await expect(page.getByTestId(`settings-override-${TERMS}`)).toHaveCount(0);
    // Never « enregistré » for a value given up: the toast names it, and « Annuler » puts the 45 back.
    await expect(toast(page)).toContainText('reprend la valeur');
    await toast(page).getByTestId('toast-action').click();
    await expect(terms).toHaveValue('45');
    await expect(page.getByTestId(`settings-override-${TERMS}`)).toHaveCount(1);
    await page.getByTestId(`settings-reset-${TERMS}`).click();
    await expect(terms).toHaveValue('30');
  } finally {
    await forgetCompanyTerms(page);
  }
});

test('a value the API refuses is said on its own field, with its rule, and nothing is saved', async ({
  page,
}) => {
  await signIn(page);
  await inACompany(page, CSRF);
  await forgetCompanyTerms(page);
  try {
    await page.goto('/settings');
    const terms = page.getByTestId('field-document__payment_terms_days');
    await expect(terms).toHaveValue('30');

    // The screen refuses 9999 itself, so the request it sends is rewritten on its way: what comes back is the
    // API's own answer, read by the screen as a refusal of that one setting.
    await page.route('**/api/companies/*/settings', async (route) => {
      const request = route.request();
      if (request.method() !== 'PUT') return route.continue();
      const body = request.postDataJSON() as {
        changes: { key: string; value: unknown; version: number }[];
      };
      const changes = body.changes.map((change) =>
        change.key === TERMS ? { ...change, value: 9999 } : change,
      );
      await route.continue({ postData: JSON.stringify({ ...body, changes }) });
    });
    await terms.fill('45');
    const answered = page.waitForResponse(
      (response) =>
        response.request().method() === 'PUT' &&
        /\/settings$/.test(new URL(response.url()).pathname),
    );
    await page.getByTestId('record-save').click();
    expect((await answered).status()).toBe(422);

    await expect(page.getByTestId('settings-error')).toContainText('corrigez les champs marqués');
    await expect(page.getByTestId(`field-error-${TERMS.replaceAll('.', '__')}`)).toContainText(
      '365',
    );
    await page.unroute('**/api/companies/*/settings');

    // Read from the API rather than reloaded: the typed 45 is still unsaved, and the page rightly holds a reload.
    const stored = await page.evaluate(async (key) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const rows = (await (
        await fetch(`/api/companies/${me.company.id}/settings?chain=parties`, {
          headers: { accept: 'application/json' },
        })
      ).json()) as { key: string; value: unknown }[];
      return rows.find((row) => row.key === key)?.value;
    }, TERMS);
    expect(stored).toBe(30);
  } finally {
    await forgetCompanyTerms(page);
  }
});

test('the fields of one row line up, whatever the length of their labels', async ({ page }) => {
  // Audit 2026-10-06 V-15: a sentence as a label pushed its input 40 px below its neighbour's, and two checkboxes
  // centred on texts of one and two lines did not line up.
  await signIn(page);
  await inACompany(page, CSRF);
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
  // Each « Reçu par » choice beside a typed field: their labels hold one line, so their boxes line up. The pairs follow
  // the declarations' order: a setting declared in this section moves every field after it by one.
  const box = '.mat-mdc-text-field-wrapper, [role="combobox"]';
  expect(await top('field-wrapper-watch__late_after_days', box)).toBe(
    await top('field-wrapper-invoice__receiver', box),
  );
  expect(await top('field-wrapper-reminders__hour', box)).toBe(
    await top('field-wrapper-purchase_receipt__receiver', box),
  );
  expect(await top('field-wrapper-quote__receiver', box)).toBe(
    await top('field-wrapper-document__payment_terms_days', box),
  );
  expect(await top('field-document__how_to_pay', '.mdc-checkbox__background')).toBe(
    await top('field-document__amount_in_words', '.mdc-checkbox__background'),
  );
  // The watermark's strength and copies share a row; its spread takes the next one whole, which keeps the logo's two
  // sizes side by side after it.
  expect(await top('field-document__watermark_intensity', self)).toBe(
    await top('field-document__watermark_copies', self),
  );
  expect(await top('field-document__logo_width', self)).toBe(
    await top('field-document__logo_height', self),
  );
  expect(await top('field-document__logo_keep_proportions', '.mdc-checkbox__background')).toBe(
    await top('field-document__paid_stamp', '.mdc-checkbox__background'),
  );
  // The last two switches share a row, then the credit limit stands beside the aged balance's first bound and its other
  // two share the last row: no cell is left alone.
  expect(await top('field-document__savings_line', '.mdc-checkbox__background')).toBe(
    await top('field-document__names_as_issued', '.mdc-checkbox__background'),
  );
  expect(await top('field-credit__limit', self)).toBe(
    await top('field-aged_balance__first_days', self),
  );
  expect(await top('field-aged_balance__second_days', self)).toBe(
    await top('field-aged_balance__third_days', self),
  );
  await expect(page.getByTestId('settings-title')).toHaveText('Valeurs par défaut');
});
