// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { inACompany, signIn } from './session';
import { wcagViolations } from './axe';

// G6 delivery notes through the real stack: in the seeded Tunisian company, the owner drafts a note for a customer
// made for the run, two laptops at 1250 under the 19 % VAT, validates it and finds it numbered, downloads its PDF
// rendered by Gotenberg, marks it delivered and finds it in the list. One database is shared by the whole suite and
// notes are never deleted, so the customer is unique to the run and deactivated afterwards.
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

/** Deactivates the run's customer; its numbered note stays, as every numbered note does. */
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

test('a delivery note is drafted, numbered at validation, printed and delivered', async ({
  page,
}) => {
  const run = Date.now().toString(36).toUpperCase();
  const customerNumber = `E2E-DN-${run}`;
  await signIn(page);
  await inACompany(page, CSRF);
  await createCustomer(page, customerNumber);
  try {
    await page.goto('/delivery-notes/new');
    // Typed, not scrolled to: the picker answers the few that match, and this company's book is long.
    await page.getByTestId('delivery-note-customer').fill(customerNumber);
    await page.getByRole('option', { name: new RegExp(`^${customerNumber} · `) }).click();
    await page.getByTestId('field-customerReference').fill(`PO-${run}`);
    await page.getByTestId('line-0-description').fill('Portable 14 pouces');
    await page.getByTestId('line-0-quantity').fill('2');
    await page.getByTestId('line-0-price').fill('1250');
    await page.getByTestId('line-0').getByRole('checkbox', { name: /19/ }).check();
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('document-action-save').click();

    await expect(page).toHaveURL(/\/delivery-notes\/[0-9a-f-]{36}$/);
    await expect(page.getByTestId('delivery-note-totals')).toContainText('2 975,000');
    const draftPdf = await download(
      page,
      (await page.getByTestId('document-action-pdf').getAttribute('href')) ?? '',
    );
    expect([draftPdf.status, draftPdf.magic]).toEqual([200, '%PDF-']);

    await page.getByTestId('document-action-validate').click();
    await page.getByTestId('confirm-run').click();
    await expect(page.getByTestId('delivery-note-title')).toHaveText(/BL-\d{4}-\d{5}/);
    const noteNumber = ((await page.getByTestId('delivery-note-title').textContent()) ?? '').trim();
    await expect(page.getByTestId('line-0-quantity')).toBeDisabled();
    expect(await wcagViolations(page)).toEqual([]);

    const pdf = await download(
      page,
      (await page.getByTestId('document-action-pdf').getAttribute('href')) ?? '',
    );
    expect(pdf.status).toBe(200);
    expect(pdf.type).toContain('application/pdf');
    expect(pdf.disposition).toContain(`${noteNumber}.pdf`);
    expect(pdf.magic).toBe('%PDF-');

    // Delivering asks for its day in a dialog, so the bar carries actions and not a date field.
    await page.getByTestId('document-action-deliver').click();
    await page.getByTestId('delivery-note-deliver').click();
    await expect(page.getByTestId('document-action-deliver')).toHaveCount(0);
    await expect(page.getByTestId('delivery-note-status')).toContainText(/Livré|Delivered/);

    // The API pages this list and the shared company outgrows one page, so the row is searched for rather than
    // expected among the newest few.
    await page.goto('/delivery-notes');
    await page.getByTestId('list-filter').fill(noteNumber);
    await expect(page.getByTestId('delivery-notes-table')).toContainText(noteNumber);
  } finally {
    await retire(page, customerNumber);
  }
});
