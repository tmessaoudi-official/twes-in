// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { inACompany, signIn } from './session';
import { wcagViolations } from './axe';
import { choose, expectFacetCount } from './select';

// Quotes through the real stack: in the seeded Tunisian company, the owner drafts a quote for a customer made for the
// run, previews its PDF, marks it sent and finds it numbered from its own series, records the customer's yes, then
// invoices it and lands on the draft invoice it became. Numbered documents are never deleted, so the customer is
// unique to the run and deactivated afterwards.
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

/** Deactivates the run's customer; its numbered quote stays, as every numbered document does. */
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

test('a quote is drafted, sent, accepted and invoiced into a draft invoice', async ({ page }) => {
  const run = Date.now().toString(36).toUpperCase();
  const customerNumber = `E2E-DV-${run}`;
  await signIn(page);
  await inACompany(page, CSRF);
  await createCustomer(page, customerNumber);
  try {
    await page.goto('/quotes/new');
    // Typed, not scrolled to: the picker answers the few that match, and this company's book is long.
    await page.getByTestId('quote-customer').fill(customerNumber);
    await page.getByRole('option', { name: new RegExp(`^${customerNumber} · `) }).click();
    await page.getByTestId('field-customerReference').fill(`RFQ-${run}`);
    await page.getByTestId('line-0-description').fill(`Tournage ${run}`);
    await page.getByTestId('line-0-quantity').fill('2');
    await page.getByTestId('line-0-price').fill('1250');
    await choose(page, 'line-0-taxes', /19/);
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('document-action-save').click();

    await expect(page).toHaveURL(/\/quotes\/[0-9a-f-]{36}$/);
    await expect(page.getByTestId('quote-totals')).toContainText('2 975,000');
    const draftPdf = await download(
      page,
      (await page.getByTestId('document-action-pdf').getAttribute('href')) ?? '',
    );
    expect([draftPdf.status, draftPdf.magic]).toEqual([200, '%PDF-']);

    await page.getByTestId('document-action-send').click();
    await page.getByTestId('confirm-run').click();
    await expect(page.getByTestId('quote-title')).toHaveText(/DEV-\d{4}-(\d{2}-)?\d{5}/);
    const quoteNumber = ((await page.getByTestId('quote-title').textContent()) ?? '').trim();
    await expect(page.getByTestId('quote-valid-until')).toBeVisible();
    await expect(page.getByTestId('quote-status')).toContainText(/Envoyé|Sent/);
    expect(await wcagViolations(page)).toEqual([]);
    const pdf = await download(
      page,
      (await page.getByTestId('document-action-pdf').getAttribute('href')) ?? '',
    );
    expect(pdf.status).toBe(200);
    expect(pdf.disposition).toContain(`${quoteNumber}.pdf`);

    // The customer's answer is asked in a dialog: the day, and the signed copy when there is one.
    await page.getByTestId('document-action-accept').click();
    await expect(page.getByTestId('quote-answered-on')).toBeFocused();
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('quote-answer-confirm').click();
    await expect(page.getByTestId('quote-status')).toContainText(/Accepté|Accepted/);

    await page.getByTestId('document-action-invoice').click();
    await expect(page).toHaveURL(/\/invoices\/[0-9a-f-]{36}$/);
    await expect(page.getByTestId('line-0-description')).toHaveValue(`Tournage ${run}`);
    await expect(page.getByTestId('field-customerReference')).toHaveValue(`RFQ-${run}`);

    // The API pages this list and the shared company outgrows one page, so the row is searched for.
    await page.goto('/quotes');
    await page.getByTestId('list-filter').fill(quoteNumber);
    await expect(page.getByTestId('quotes-table')).toContainText(quoteNumber);
    await expectFacetCount(page, 'list-facet-status-accepted', '1');
    await expectFacetCount(page, 'list-facet-status-sent', '0');
    expect(await wcagViolations(page)).toEqual([]);
  } finally {
    await retire(page, customerNumber);
  }
});
