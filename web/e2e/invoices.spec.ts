// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { inACompany, signIn } from './session';
import { toast } from './toast';
import { wcagViolations } from './axe';

// G7 invoices through the real stack (docs/SPEC.md § 8 row 10): in the seeded Tunisian company, the owner drafts an
// invoice for a customer made for the run, two days of consulting at 500 under the 19 % VAT, issues it and finds it
// numbered, downloads its PDF rendered by Gotenberg, records a payment and deletes it again, then corrects the whole
// invoice with a credit note, stating why, which leaves nothing due. One database is shared by the whole suite and
// issued documents are never deleted, so the customer is unique to the run and deactivated afterwards.
const CSRF = '0123456789abcdef0123456789abcdef';

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

/** Leaves every field, so a single key is a shortcut rather than a letter typed. */
async function outOfFields(page: Page): Promise<void> {
  await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur());
}

/** Deactivates the run's customer; its issued documents stay, as every issued document does. */
async function retire(page: Page, number: string): Promise<void> {
  await page.evaluate(
    async ([csrf, customerNumber]) => {
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
      const customer = await customerNumbered(base, customerNumber);
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
  await inACompany(page, CSRF);
  await createCustomer(page, customerNumber);
  try {
    // N opens a new invoice from its own list (docs/SPEC.md § 7, 2026-09-24 22:51).
    await page.goto('/invoices');
    await expect(page.getByTestId('invoices-table')).toBeVisible();
    await outOfFields(page);
    await page.keyboard.press('n');
    await expect(page).toHaveURL(/\/invoices\/new$/);
    // Typed, not scrolled to: the picker answers the few that match, and this company's book is long.
    await page.getByTestId('invoice-customer').fill(customerNumber);
    await page.getByRole('option', { name: new RegExp(`^${customerNumber} · `) }).click();
    await page.getByTestId('field-customerReference').fill(`PO-${run}`);
    await page.getByTestId('line-0-description').fill('Conseil, deux jours');
    await page.getByTestId('line-0-quantity').fill('2');
    await page.getByTestId('line-0-price').fill('500');
    await page.getByTestId('line-0').getByRole('checkbox', { name: /19/ }).check();
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('document-action-save').click();

    await expect(page).toHaveURL(/\/invoices\/[0-9a-f-]{36}$/);
    const invoiceUrl = page.url();
    await expect(page.getByTestId('invoice-totals')).toContainText('1 000,000');
    await expect(page.getByTestId('invoice-totals')).toContainText('190,000');
    const draftPdf = await download(
      page,
      (await page.getByTestId('document-action-pdf').getAttribute('href')) ?? '',
    );
    expect([draftPdf.status, draftPdf.magic]).toEqual([200, '%PDF-']);

    // E runs the state's next step, and asks first exactly as the button does.
    await outOfFields(page);
    await page.keyboard.press('e');
    await page.getByTestId('confirm-run').click();
    await expect(page.getByTestId('document-action-issue')).toHaveCount(0);
    await expect(page.getByTestId('invoice-status')).toContainText(/Émise|Issued/);
    const invoiceNumber = ((await page.getByTestId('invoice-title').textContent()) ?? '').trim();
    expect(invoiceNumber).toMatch(/\d{4}/);
    await expect(page.getByTestId('line-0-quantity')).toBeDisabled();
    const due = ((await page.getByTestId('invoice-amount-due').textContent()) ?? '').trim();
    expect(await wcagViolations(page)).toEqual([]);

    const pdf = await download(
      page,
      (await page.getByTestId('document-action-pdf').getAttribute('href')) ?? '',
    );
    expect(pdf.status).toBe(200);
    expect(pdf.type).toContain('application/pdf');
    expect(pdf.disposition).toContain(`${invoiceNumber}.pdf`);
    expect(pdf.magic).toBe('%PDF-');

    // A payment is one answer to one question, so it is asked in a dialog (design review finding 3).
    // Once issued, the next step E runs is recording a payment.
    await outOfFields(page);
    await page.keyboard.press('e');
    await page.getByTestId('field-amount').fill('100');
    await page.getByTestId('field-reference').fill(`VIR ${run}`);
    await page.getByTestId('invoice-payment-record').click();
    await expect(toast(page)).toContainText('Le paiement a été enregistré.');
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

    // A credit note is rare, so it sits behind "⋮".
    await page.getByTestId('document-more').click();
    await page.getByTestId('document-menu-credit-note').click();
    // It asks why before anything is drafted: the reason is printed on the credit note.
    await expect(page.getByTestId('credit-note-dialog-title')).toBeVisible();
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('credit-note-create').click();
    await expect(page.getByTestId('credit-note-dialog-title')).toBeVisible();
    await expect(page).toHaveURL(invoiceUrl);
    await page.getByTestId('field-reason').fill('Geste commercial après un retard');
    await page.getByTestId('credit-note-create').click();
    await expect(page).not.toHaveURL(invoiceUrl);
    await expect(page.getByTestId('invoice-title')).toContainText(
      /Avoir en brouillon|Draft credit note/,
    );
    await expect(page.getByTestId('invoice-credit-reason')).toContainText(
      'Geste commercial après un retard',
    );
    await expect(page.getByTestId('invoice-payments')).toHaveCount(0);
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('document-action-issue').click();
    await page.getByTestId('confirm-run').click();
    await expect(page.getByTestId('document-action-issue')).toHaveCount(0);
    await expect(page.getByTestId('invoice-status')).toContainText(/Émise|Issued/);

    await page.getByTestId('invoice-corrects').click();
    await expect(page).toHaveURL(invoiceUrl);
    await expect(page.getByTestId('invoice-status')).toContainText(/Soldée|Settled/);
    await expect(page.getByTestId('invoice-amount-due')).toContainText(/^\s*0,000/);
    await expect(page.getByTestId('document-action-record-payment')).toHaveCount(0);

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
