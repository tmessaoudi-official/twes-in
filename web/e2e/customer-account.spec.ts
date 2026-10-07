// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { inACompany, signIn } from './session';

// The customer's running account through the real stack: a new customer owes nothing, nothing is late, and the
// account stands above the statement it agrees with. The customer is deactivated afterwards (customers are never
// deleted), as the shared database outlives the run.
const CSRF = '0123456789abcdef0123456789abcdef';

/** A customer created through the API, its id answered: the customers screens have their own run. */
async function createCustomer(page: Page, number: string): Promise<string> {
  return page.evaluate(
    async ([csrf, customerNumber]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const created = await fetch(`/api/companies/${me.company.id}/customers`, {
        method: 'POST',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: JSON.stringify({
          number: customerNumber,
          kind: 'individual',
          customerGroupId: null,
          taxRegime: 'standard',
          name: `Client ${customerNumber}`,
          legalName: null,
          identifiers: {},
          email: null,
          phone: null,
          website: null,
          billingAddressLine1: 'Rue de Rome',
          billingAddressLine2: null,
          billingPostalCode: '1000',
          billingCity: 'Tunis',
          billingCountryCode: 'TN',
          shippingAddressLine1: null,
          shippingAddressLine2: null,
          shippingPostalCode: null,
          shippingCity: null,
          shippingCountryCode: null,
          defaultTaxComponentIds: [],
          defaultDiscountRate: null,
          notes: null,
          isActive: true,
          customFields: {},
        }),
      });
      if (!created.ok) throw new Error(`creating ${customerNumber} answered ${created.status}`);
      return ((await created.json()) as { id: string }).id;
    },
    [CSRF, number] as const,
  );
}

async function retire(page: Page, id: string): Promise<void> {
  await page.evaluate(
    async ([csrf, customerId]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}/customers/${customerId}`;
      // The write shape has no id: a body naming one is refused.
      const { id: _id, ...fields } = (await (await fetch(base)).json()) as { id: string };
      const revised = await fetch(base, {
        method: 'PUT',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: JSON.stringify({ ...fields, isActive: false }),
      });
      if (!revised.ok) throw new Error(`retiring ${customerId} answered ${revised.status}`);
    },
    [CSRF, id] as const,
  );
}

test("a customer's account stands above their statement: what is due, what is late, the net balance", async ({
  page,
}) => {
  await signIn(page);
  await inACompany(page, CSRF);
  const id = await createCustomer(page, `ACC-${Date.now()}`);
  try {
    await page.goto(`/customers/${id}`);
    await page.getByRole('tab', { name: 'Relevé' }).click();

    const account = page.getByTestId('customer-account');
    await expect(account.getByRole('heading', { name: 'Compte du client' })).toBeVisible();
    await expect(account.getByTestId('account-balance')).toContainText('0,000');
    await expect(account.getByTestId('account-overdue')).toContainText('0,000');
    await expect(account.getByTestId('account-overdue-detail')).toHaveCount(0);
    await expect(account.getByTestId('account-owed')).toContainText('0,000');
    await expect(page.getByTestId('statement-closing')).toContainText('0,000');
  } finally {
    await retire(page, id);
  }
});
