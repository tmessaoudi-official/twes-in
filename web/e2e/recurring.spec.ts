// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { wcagViolations } from './axe';
import { inACompany, signIn } from './session';
import { toast } from './toast';

// Row 86 through the real stack: an invoice is made recurring from its « ⋮ », the toast leads to « Récurrentes », a tab
// of Factures, which lists it; it is paused there and deleted. The database is shared by the whole suite: the draft and
// customer made for the run are cancelled and retired, and the recurring invoice is deleted by the scenario itself.
const CSRF = '0123456789abcdef0123456789abcdef';

/** A customer and a draft invoice of one line for it, made for the run. */
async function aDraftInvoice(
  page: Page,
  number: string,
): Promise<{ customer: string; invoice: string }> {
  return page.evaluate(
    async ([csrf, customerNumber]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}`;
      const send = async (path: string, body: unknown): Promise<{ id: string }> => {
        const response = await fetch(`${base}${path}`, {
          method: 'POST',
          headers: { 'content-type': 'application/json', 'csrf-token': csrf },
          body: JSON.stringify(body),
        });
        if (!response.ok) throw new Error(`POST ${path} answered ${response.status}`);
        return (await response.json()) as { id: string };
      };
      const customer = await send('/customers', {
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
      });
      const options = (await (await fetch(`${base}/invoice-options`)).json()) as {
        units: { id: string; code: string }[];
      };
      const unit = options.units.find((each) => each.code === 'C62') ?? options.units[0];
      const invoice = await send('/invoices', {
        customerId: customer.id,
        establishmentId: null,
        lines: [
          {
            description: 'Maintenance mensuelle du tour',
            quantity: '1',
            unitId: unit?.id,
            unitPriceNet: '250',
            discountRate: null,
            taxComponentIds: [],
          },
        ],
      });
      return { customer: customer.id, invoice: invoice.id };
    },
    [CSRF, number] as const,
  );
}

async function tidy(page: Page, made: { customer: string; invoice: string }): Promise<void> {
  const statuses = await page.evaluate(
    async ([csrf, ids]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}`;
      // Whatever a failed run left behind: a recurring invoice of this model goes first.
      const recurring = (await (await fetch(`${base}/recurring-invoices`)).json()) as {
        id: string;
        modelInvoiceId: string;
      }[];
      for (const each of recurring.filter((one) => one.modelInvoiceId === ids.invoice)) {
        const deleted = await fetch(`${base}/recurring-invoices/${each.id}`, {
          method: 'DELETE',
          headers: { 'csrf-token': csrf },
        });
        if (!deleted.ok) throw new Error(`DELETE recurring answered ${deleted.status}`);
      }
      const cancelled = await fetch(`${base}/invoices/${ids.invoice}/cancel`, {
        method: 'POST',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: 'null',
      });
      // The write shape has no id: a body naming one is refused.
      const fields = (await (await fetch(`${base}/customers/${ids.customer}`)).json()) as Record<
        string,
        unknown
      >;
      delete fields['id'];
      const retired = await fetch(`${base}/customers/${ids.customer}`, {
        method: 'PUT',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: JSON.stringify({ ...fields, isActive: false }),
      });
      return [cancelled.status, retired.status];
    },
    [CSRF, made] as const,
  );
  expect(statuses).toEqual([200, 200]);
}

test('an invoice made recurring is listed under « Récurrentes », paused there and deleted', async ({
  page,
}) => {
  await signIn(page);
  await inACompany(page, CSRF);
  const number = `REC-${Date.now()}`;
  const made = await aDraftInvoice(page, number);
  try {
    await page.goto(`/invoices/${made.invoice}`);
    await page.getByTestId('document-more').click();
    await page.getByTestId('document-menu-make-recurring').click();
    await expect(page.getByTestId('make-recurring-title')).toBeVisible();
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('make-recurring-confirm').click();

    await expect(toast(page)).toContainText('récurrente');
    await toast(page).getByTestId('toast-action').click();
    await expect(page).toHaveURL(/\/invoices\/recurring$/);
    await expect(page.getByTestId('recurring-tab')).toBeVisible();

    const row = page
      .getByTestId('recurring-table')
      .locator('[data-testid^="recurring-"]', { hasText: `Client ${number}` });
    await expect(row).toHaveCount(1);
    await expect(row.locator('[data-column="frequency"]')).toContainText('Chaque mois');
    await expect(row.locator('[data-column="state"]')).toContainText('Active');
    expect(await wcagViolations(page)).toEqual([]);

    await row.getByTestId(/^row-action-pause-/).click();
    await expect(row.locator('[data-column="state"]')).toContainText('En pause');

    await row.getByTestId(/^row-more-/).click();
    await page.getByTestId(/^row-menu-delete-/).click();
    await page.getByTestId('confirm-run').click();
    await expect(row).toHaveCount(0);
  } finally {
    await tidy(page, made);
  }
});
