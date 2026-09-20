// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { signIn } from './session';
import { wcagViolations } from './axe';

// G4 custom fields through the real stack: in the seeded Tunisian company, the owner declares a choice field for
// customers, files a customer with a value for it, and finds the value again. One database is shared by the whole
// suite, so the field's key is unique to the run and optional (a required one would refuse every other scenario's
// customers), and both the field and the customer are retired afterwards (neither is ever deleted).
const CSRF = '0123456789abcdef0123456789abcdef';

/** Retires the run's field and deactivates the run's customer. */
async function retire(page: Page, key: string, number: string): Promise<void> {
  await page.evaluate(
    async ([csrf, fieldKey, customerNumber]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      // The list is paged: the customer is found by its number, then read whole as the single record it is.
      const customerNumbered = async (company: string, wanted: string) => {
        const listed = (await (
          await fetch(`${company}/customers?q=${encodeURIComponent(wanted)}`)
        ).json()) as { member: { id: string; number: string }[] };
        const hit = listed.member.find((row) => row.number === wanted);
        return hit === undefined
          ? undefined
          : ((await (await fetch(`${company}/customers/${hit.id}`)).json()) as {
              id: string;
              number: string;
            });
      };
      const base = `/api/companies/${me.company.id}`;
      const headers = { 'content-type': 'application/json', 'csrf-token': csrf };
      const customer = await customerNumbered(base, customerNumber);
      // The write shapes have no id: a body naming one is refused.
      if (customer) {
        const { id, ...values } = customer;
        const revised = await fetch(`${base}/customers/${id}`, {
          method: 'PUT',
          headers,
          body: JSON.stringify({ ...values, isActive: false }),
        });
        if (!revised.ok) throw new Error(`retiring ${customerNumber} answered ${revised.status}`);
      }
      const fields = (await (await fetch(`${base}/custom-fields?entity=customer`)).json()) as {
        id: string;
        key: string;
      }[];
      const field = fields.find((row) => row.key === fieldKey);
      if (field) {
        const { id, ...declaration } = field;
        const retired = await fetch(`${base}/custom-fields/${id}`, {
          method: 'PUT',
          headers,
          body: JSON.stringify({ ...declaration, isActive: false }),
        });
        if (!retired.ok) throw new Error(`retiring ${fieldKey} answered ${retired.status}`);
      }
    },
    [CSRF, key, number] as const,
  );
}

test('a custom field declared for customers is filled in on a customer and kept', async ({
  page,
}) => {
  const run = Date.now().toString(36);
  const key = `e2e_${run}`;
  const label = `Secteur ${run}`;
  const number = `CF-${run.toUpperCase()}`;
  const field = `field-custom__${key}`;
  await signIn(page);
  try {
    await page.goto('/company/custom-fields');
    await page.getByTestId('custom-field-add').click();
    await page.getByTestId('field-key').fill(key);
    await page.getByTestId('field-label').fill(label);
    await page.getByTestId('field-type').click();
    await page.getByRole('option', { name: /^(Choice|Choix)$/ }).click();
    await page.getByTestId('field-choices').fill('Détail\nGros');
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('custom-field-save').click();
    await expect(page.getByTestId(`custom-field-${key}`)).toContainText(label);

    await page.goto('/customers/new');
    await page.getByTestId('field-number').fill(number);
    await page.getByTestId('field-name').fill(`Médina Import ${run}`);
    await page.getByTestId('field-identifier__matricule_fiscal').fill('1234567A/B/M/000');
    await page.getByTestId(field).click();
    await page.getByRole('option', { name: 'Gros' }).click();
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('record-save').click();

    await expect(page).toHaveURL(/\/customers\/[0-9a-f-]{36}$/);
    await page.reload();
    await expect(page.getByTestId(field)).toContainText('Gros');
  } finally {
    await retire(page, key, number);
  }
});
