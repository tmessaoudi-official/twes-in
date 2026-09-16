// SPDX-License-Identifier: AGPL-3.0-or-later
import AxeBuilder from '@axe-core/playwright';
import { expect, type Page, test } from '@playwright/test';
import { signIn } from './session';

// G7 invoices through the real stack (docs/SPEC.md § 8 row 10): in the seeded Tunisian company, the owner drafts an
// invoice for a customer made for the run, two days of consulting at 500 under the 19 % VAT, issues it and finds it
// numbered, downloads its PDF rendered by Gotenberg, records a payment and deletes it again, then corrects the whole
// invoice with a credit note, which leaves nothing due. One database is shared by the whole suite and issued documents
// are never deleted, so the customer is unique to the run and deactivated afterwards.
const CSRF = '0123456789abcdef0123456789abcdef';

async function wcagViolations(page: Page): Promise<string[]> {
  const axe = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
    .analyze();
  return axe.violations.map(
    (violation) =>
      `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`,
  );
}

/** A customer billed in Tunis under the standard regime, through the API: the customers screens have their own run. */
async function createCustomer(page: Page, number: string): Promise<void> {
  await page.evaluate(
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
    },
    [CSRF, number] as const,
  );
}

/**
 * A PDF as the browser downloads it, with the session cookie the page holds: Playwright's own request context does not
 * send a cookie the browser keeps for a secure origin only.
 */
async function download(
  page: Page,
  href: string,
): Promise<{ status: number; type: string; disposition: string; magic: string }> {
  return page.evaluate(async (url) => {
    const response = await fetch(url);
    const bytes = new Uint8Array(await response.arrayBuffer());
    return {
      status: response.status,
      type: response.headers.get('content-type') ?? '',
      disposition: response.headers.get('content-disposition') ?? '',
      magic: String.fromCharCode(...bytes.slice(0, 5)),
    };
  }, href);
}

/** Deactivates the run's customer; its issued documents stay, as every issued document does. */
async function retire(page: Page, number: string): Promise<void> {
  await page.evaluate(
    async ([csrf, customerNumber]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}`;
      const customers = (await (await fetch(`${base}/customers`)).json()) as {
        id: string;
        number: string;
      }[];
      const customer = customers.find((row) => row.number === customerNumber);
      if (customer) {
        // The write shape has no id: a body naming one is refused.
        const { id, ...fields } = customer;
        const revised = await fetch(`${base}/customers/${id}`, {
          method: 'PUT',
          headers: { 'content-type': 'application/json', 'csrf-token': csrf },
          body: JSON.stringify({ ...fields, isActive: false }),
        });
        if (!revised.ok) throw new Error(`retiring ${customerNumber} answered ${revised.status}`);
      }
    },
    [CSRF, number] as const,
  );
}

test('an invoice is drafted, issued, printed, paid, and corrected by a credit note', async ({
  page,
}) => {
  test.setTimeout(90_000);
  const run = Date.now().toString(36).toUpperCase();
  const customerNumber = `E2E-INV-${run}`;
  await signIn(page);
  await createCustomer(page, customerNumber);
  try {
    await page.goto('/invoices/new');
    await page.getByTestId('field-customerId').click();
    await page.getByRole('option', { name: new RegExp(`^${customerNumber} · `) }).click();
    await page.getByTestId('field-customerReference').fill(`PO-${run}`);
    await page.getByTestId('line-0-description').fill('Conseil, deux jours');
    await page.getByTestId('line-0-quantity').fill('2');
    await page.getByTestId('line-0-price').fill('500');
    await page.getByTestId('line-0').getByRole('checkbox', { name: /19/ }).check();
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('invoice-save').click();

    await expect(page).toHaveURL(/\/invoices\/[0-9a-f-]{36}$/);
    const invoiceUrl = page.url();
    await expect(page.getByTestId('invoice-totals')).toContainText('1 000,000');
    await expect(page.getByTestId('invoice-totals')).toContainText('190,000');
    const draftPdf = await download(
      page,
      (await page.getByTestId('invoice-pdf').getAttribute('href')) ?? '',
    );
    expect([draftPdf.status, draftPdf.magic]).toEqual([200, '%PDF-']);

    await page.getByTestId('invoice-issue').click();
    await expect(page.getByTestId('invoice-issue')).toHaveCount(0);
    await expect(page.getByTestId('invoice-status')).toContainText(/Émise|Issued/);
    const invoiceNumber = ((await page.getByTestId('invoice-title').textContent()) ?? '').trim();
    expect(invoiceNumber).toMatch(/\d{4}/);
    await expect(page.getByTestId('line-0-quantity')).toBeDisabled();
    const due = ((await page.getByTestId('invoice-amount-due').textContent()) ?? '').trim();
    expect(await wcagViolations(page)).toEqual([]);

    const pdf = await download(
      page,
      (await page.getByTestId('invoice-pdf').getAttribute('href')) ?? '',
    );
    expect(pdf.status).toBe(200);
    expect(pdf.type).toContain('application/pdf');
    expect(pdf.disposition).toContain(`${invoiceNumber}.pdf`);
    expect(pdf.magic).toBe('%PDF-');

    await page.getByTestId('field-amount').fill('100');
    await page.getByTestId('field-reference').fill(`VIR ${run}`);
    await page.getByTestId('invoice-payment-record').click();
    await expect(page.getByTestId('invoice-payment-recorded')).toBeVisible();
    await expect(page.getByTestId('invoice-status')).toContainText(
      /Partiellement payée|Partly paid/,
    );
    await expect(page.getByTestId('invoice-payments')).toContainText(`VIR ${run}`);
    await expect(page.getByTestId('invoice-amount-due')).not.toHaveText(due);

    const payment = page.locator('[data-testid^="payment-"][data-testid$="-delete"]');
    await payment.click();
    await page.locator('[data-testid$="-delete-confirm"]').click();
    await expect(page.getByTestId('invoice-payments-none')).toBeVisible();
    await expect(page.getByTestId('invoice-status')).toContainText(/Émise|Issued/);
    await expect(page.getByTestId('invoice-amount-due')).toHaveText(due);

    await page.getByTestId('invoice-credit-note').click();
    await expect(page).not.toHaveURL(invoiceUrl);
    await expect(page.getByTestId('invoice-title')).toContainText(
      /Avoir en brouillon|Draft credit note/,
    );
    await expect(page.getByTestId('invoice-payments')).toHaveCount(0);
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('invoice-issue').click();
    await expect(page.getByTestId('invoice-issue')).toHaveCount(0);
    await expect(page.getByTestId('invoice-status')).toContainText(/Émise|Issued/);

    await page.getByTestId('invoice-corrects').click();
    await expect(page).toHaveURL(invoiceUrl);
    await expect(page.getByTestId('invoice-status')).toContainText(/Payée|Paid/);
    await expect(page.getByTestId('invoice-amount-due')).toContainText(/^\s*0,000/);
    await expect(page.getByTestId('invoice-payment-record')).toHaveCount(0);

    await page.goto('/invoices');
    await expect(page.getByTestId('invoices-table')).toContainText(invoiceNumber);

    // The home page lays out the API's own summary: the same digits, whatever the locale does with separators.
    await page.goto('/');
    await expect(page.getByTestId('home-outstanding')).toBeVisible();
    const summary = await page.evaluate(async () => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      return (await (await fetch(`/api/companies/${me.company.id}/invoice-summary`)).json()) as {
        outstanding: string;
        today: string;
      };
    });
    const digits = (value: string): string => value.replace(/\D/g, '');
    expect(digits((await page.getByTestId('home-outstanding').textContent()) ?? '')).toContain(
      digits(summary.outstanding),
    );
    await expect(page.getByTestId(`home-month-${summary.today.slice(0, 7)}`)).toHaveAttribute(
      'data-current',
      'true',
    );
    expect(await wcagViolations(page)).toEqual([]);
  } finally {
    await retire(page, customerNumber);
  }
});
