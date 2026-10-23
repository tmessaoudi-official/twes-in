// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { inACompany, signIn } from './session';
import { wcagViolations } from './axe';
import { choose, expectFacetCount } from './select';

// Quotes through the real stack: in the seeded Tunisian company, the owner drafts a quote for a customer made for the
// run, previews its PDF, marks it sent and finds it numbered from its own series, records the customer's yes, then
// invoices it and lands on the draft invoice it became, onto which a second accepted quote is added. Numbered documents are never deleted, so the customer is
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

/**
 * A quote of one line for the run’s customer, sent and accepted through the API, at the company’s default line taxes:
 * the screens that make one are played by the scenario above. Answers the quote's address on screen.
 */
async function acceptedQuote(
  page: Page,
  customerNumber: string,
  description: string,
): Promise<string> {
  const id = await page.evaluate(
    async ([csrf, wanted, line]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}`;
      const listed = (await (
        await fetch(`${base}/customers?q=${encodeURIComponent(wanted)}`)
      ).json()) as { member: { id: string; number: string }[] };
      const customer = listed.member.find((row) => row.number === wanted);
      if (customer === undefined) throw new Error(`no customer ${wanted}`);
      const options = (await (await fetch(`${base}/quote-options`)).json()) as {
        units: { id: string }[];
        taxes: { id: string; kind: string; isDefault: boolean }[];
      };
      const post = async (path: string, body: unknown) => {
        const answer = await fetch(`${base}/quotes${path}`, {
          method: 'POST',
          headers: { 'content-type': 'application/json', 'csrf-token': csrf },
          body: JSON.stringify(body),
        });
        if (!answer.ok) {
          throw new Error(`POST quotes${path} answered ${answer.status}: ${await answer.text()}`);
        }
        return (await answer.json()) as { id: string };
      };
      const quote = await post('', {
        customerId: customer.id,
        establishmentId: null,
        customerReference: null,
        notesPrinted: null,
        notesInternal: null,
        discountAmount: null,
        lines: [
          {
            productId: null,
            description: line,
            quantity: '1',
            unitId: options.units[0]!.id,
            unitPriceNet: '400',
            discountRate: null,
            discountAmount: null,
            // The stamp is a default too, but charged on the document, never on a line.
            taxComponentIds: options.taxes
              .filter((tax) => tax.isDefault && tax.kind === 'percentage_line')
              .map((tax) => tax.id),
          },
        ],
      });
      await post(`/${quote.id}/send`, null);
      await post(`/${quote.id}/accept`, { answeredOn: null });
      return quote.id;
    },
    [CSRF, customerNumber, description] as const,
  );
  return `/quotes/${id}`;
}

test('a quote is drafted, sent, accepted and invoiced into a draft invoice', async ({ page }) => {
  // The quote through every screen, then a second one added to the invoice the first became.
  test.setTimeout(90_000);
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
    // A new quote is worked out as it is typed, totals included, before it is saved.
    await expect(page.getByTestId('line-0-total')).toHaveText('2 975,000');
    await expect(page.getByTestId('quote-total')).toHaveText('2 975,000');
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
    // The focused field's label floats above its outline: the dialog's content must not cut it at its top edge.
    const cut = await page.getByTestId('quote-answered-on').evaluate((input) => {
      const label = input.closest('mat-form-field')!.querySelector('.mdc-floating-label')!;
      const content = input.closest('mat-dialog-content')!;
      return content.getBoundingClientRect().top - label.getBoundingClientRect().top;
    });
    expect(cut, 'pixels of the floating label above the content edge').toBeLessThanOrEqual(0);
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('quote-answer-confirm').click();
    await expect(page.getByTestId('quote-status')).toContainText(/Accepté|Accepted/);

    await page.getByTestId('document-action-invoice').click();
    await expect(page).toHaveURL(/\/invoices\/[0-9a-f-]{36}$/);
    await expect(page.getByTestId('line-0-description')).toHaveValue(`Tournage ${run}`);
    await expect(page.getByTestId('field-customerReference')).toHaveValue(`RFQ-${run}`);

    // Another accepted quote of the same customer goes onto that draft rather than a new invoice: « Facturer » asks.
    const invoiceUrl = page.url();
    const invoiceId = invoiceUrl.slice(invoiceUrl.lastIndexOf('/') + 1);
    const secondUrl = await acceptedQuote(page, customerNumber, `Fraisage ${run}`);
    await page.goto(secondUrl);
    await expect(page.getByTestId('quote-status')).toContainText(/Accepté|Accepted/);
    await page.getByTestId('document-action-invoice').click();
    await expect(page.getByTestId(`add-to-draft-${invoiceId}`)).toBeVisible();
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId(`add-to-draft-${invoiceId}`).click();
    await expect(page).toHaveURL(invoiceUrl);
    await expect(page.getByTestId('line-0-description')).toHaveValue(`Tournage ${run}`);
    await expect(page.getByTestId('line-1-description')).toHaveValue(`Fraisage ${run}`);
    // The second quote now opens the invoice it went onto.
    await page.goto(secondUrl);
    await expect(page.getByTestId('quote-invoice')).toHaveAttribute(
      'href',
      new URL(invoiceUrl).pathname,
    );

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

test('an accepted quote takes a deposit invoice, which its final invoice gives back', async ({
  page,
}) => {
  // The quote, its deposit, the final invoice and a credit note of part of it, each issued through the screens.
  test.setTimeout(90_000);
  const run = Date.now().toString(36).toUpperCase();
  const customerNumber = `E2E-AC-${run}`;
  await signIn(page);
  await inACompany(page, CSRF);
  await createCustomer(page, customerNumber);
  try {
    await page.goto('/quotes/new');
    await page.getByTestId('quote-customer').fill(customerNumber);
    await page.getByRole('option', { name: new RegExp(`^${customerNumber} · `) }).click();
    await page.getByTestId('line-0-description').fill(`Usinage ${run}`);
    await page.getByTestId('line-0-quantity').fill('1');
    await page.getByTestId('line-0-price').fill('1000');
    await choose(page, 'line-0-taxes', /19/);
    await page.getByTestId('document-action-save').click();
    await expect(page).toHaveURL(/\/quotes\/[0-9a-f-]{36}$/);
    const quoteUrl = page.url();
    await page.getByTestId('document-action-send').click();
    await page.getByTestId('confirm-run').click();
    await expect(page.getByTestId('quote-status')).toContainText(/Envoyé|Sent/);
    await page.getByTestId('document-action-accept').click();
    await expect(page.getByTestId('quote-answered-on')).toBeFocused();
    await page.getByTestId('quote-answer-confirm').click();
    await expect(page.getByTestId('quote-status')).toContainText(/Accepté|Accepted/);

    // 30 % of 1 000 + TVA 19 %: a deposit of 300 net and 57 of tax, which the final invoice takes back as charged.
    await page.getByTestId('document-action-deposit').click();
    await expect(page.getByTestId('quote-deposit-value')).toBeVisible();
    expect(await wcagViolations(page)).toEqual([]);
    // The dialog puts the cursor on its first control, the percentage, once it has finished opening: a value typed
    // before that is typed into the choice instead, so the scan above taking long enough is not relied on.
    await expect(page.getByTestId('quote-deposit-by').locator('input:focus')).toHaveCount(1);
    await page.getByTestId('quote-deposit-value').fill('30');
    await page.getByTestId('quote-deposit-confirm').click();
    await expect(page).toHaveURL(/\/invoices\/[0-9a-f-]{36}$/);
    await expect(page.getByTestId('invoice-title')).toContainText(
      /Facture d’acompte|Deposit invoice/,
    );
    await expect(page.getByTestId('invoice-deposit')).toBeVisible();
    await expect(page.getByTestId('line-0-description')).toHaveValue(/30/);
    await page.getByTestId('document-action-issue').click();
    await page.getByTestId('confirm-run').click();
    await expect(page.getByTestId('invoice-status')).toContainText(/Émise|Issued/);
    const depositNumber = ((await page.getByTestId('invoice-title').textContent()) ?? '').trim();
    const depositId = new URL(page.url()).pathname.split('/').pop() ?? '';

    await page.getByTestId('invoice-quote').click();
    await expect(page).toHaveURL(quoteUrl);
    await expect(page.getByTestId('quote-deposit-0')).toContainText(depositNumber);
    // 300 + 57 of TVA, and the timbre fiscal the deposit invoice is charged as any invoice is.
    await expect(page.getByTestId('quote-deposit-0')).toContainText('358,000');
    expect(await wcagViolations(page)).toEqual([]);

    await page.getByTestId('document-action-invoice').click();
    await expect(page).toHaveURL(/\/invoices\/[0-9a-f-]{36}$/);
    await expect(page.getByTestId('line-0-description')).toHaveValue(`Usinage ${run}`);
    await expect(page.getByTestId('line-1-gives-back')).toBeVisible();
    await expect(page.getByTestId('line-1-description')).toContainText(depositNumber);
    // The whole is 1 000 net; the deposit gives back its 300, so 700 is left to charge net of tax.
    await expect(page.getByTestId('line-1-net')).toContainText(/[-−]\s?300,000/);
    await expect(page.getByTestId('invoice-totals')).toContainText('700,000');
    expect(await wcagViolations(page)).toEqual([]);

    // Half the work is given back: a credit note of part of the final invoice gives no deposit back, so the deposit line
    // copied with the whole leaves once saved, and nothing is left unsaved to ask about on the way out.
    await page.getByTestId('document-action-issue').click();
    await page.getByTestId('confirm-run').click();
    await expect(page.getByTestId('invoice-status')).toContainText(/Émise|Issued/);
    await page.getByTestId('document-more').click();
    await page.getByTestId('document-menu-credit-note').click();
    await expect(page.getByTestId('field-reason')).toBeFocused();
    await page.getByTestId('field-reason').fill('Moitié de l’usinage reprise');
    await page.getByTestId('credit-note-create').click();
    await expect(page.getByTestId('invoice-title')).toContainText(
      /Avoir en brouillon|Draft credit note/,
    );
    const creditUrl = page.url();
    await expect(page.getByTestId('line-1-gives-back')).toBeVisible();
    await page.getByTestId('line-0-price').fill('500');
    // Worked out as typed: the API leaves the deposit line out of a part and says which row each line it kept came
    // from, so the figures as typed land on the machining, and the deposit row keeps only the net it was saved with.
    await expect(page.getByTestId('line-0-total')).toContainText(/595,000/);
    await expect(page.getByTestId('line-1-toggle')).toHaveCount(0);
    await page.getByTestId('document-action-save').click();
    await expect(page.getByTestId('line-1-gives-back')).toHaveCount(0);
    await expect(page.getByTestId('invoice-totals')).toContainText('500,000');
    // A part gives no stamp back either, and the screen says which note will.
    await expect(page.getByTestId('document-taxes-stamp-held-back')).toBeVisible();
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('nav-invoices').click();
    await expect(page).toHaveURL(/\/invoices$/);
    await expect(page.getByTestId('confirm-title')).toHaveCount(0);
    // All of it back again: the whole invoice, so its deposit is given back as the invoice gave it.
    await page.goto(creditUrl);
    await page.getByTestId('line-0-price').fill('1000');
    await page.getByTestId('document-action-save').click();
    await expect(page.getByTestId('line-1-gives-back')).toBeVisible();
    await expect(page.getByTestId('invoice-totals')).toContainText('700,000');
    await expect(page.getByTestId('document-taxes-stamp-held-back')).toHaveCount(0);

    // The list names a deposit as one, as the accountant's file does: its VAT fell due when it was paid.
    await page.goto('/invoices');
    await page.getByTestId('list-filter').fill(depositNumber);
    await expect(page.getByTestId(`invoice-kind-${depositId}`)).toContainText(
      /Facture d’acompte|Deposit invoice/,
    );
  } finally {
    await retire(page, customerNumber);
  }
});

test('an accepted quote is delivered by a note, which names it, and is no longer invoiced as a whole', async ({
  page,
}) => {
  // « Livrer » on an accepted quote: the draft note it opens names the quote, and once validated the quote reads
  // « Livré », lists the note and offers its whole invoice no more, which would bill the goods twice.
  test.setTimeout(90_000);
  const run = Date.now().toString(36).toUpperCase();
  const customerNumber = `E2E-DL-${run}`;
  await signIn(page);
  await inACompany(page, CSRF);
  await createCustomer(page, customerNumber);
  try {
    const quoteUrl = await acceptedQuote(page, customerNumber, `Livraison ${run}`);
    await page.goto(quoteUrl);
    await expect(page.getByTestId('quote-status')).toContainText(/Accepté|Accepted/);
    const quoteNumber = (await page.getByTestId('quote-title').textContent())?.trim() ?? '';
    expect(quoteNumber).not.toBe('');
    await expect(page.getByTestId('document-action-invoice')).toBeVisible();

    await page.getByTestId('document-action-deliver').click();
    await expect(page).toHaveURL(/\/delivery-notes\/[0-9a-f-]{36}$/);
    await expect(page.getByTestId('delivery-note-quote-link')).toHaveText(quoteNumber);
    await expect(page.getByTestId('line-0-description')).toHaveValue(`Livraison ${run}`);
    await page.getByTestId('document-action-validate').click();
    await page.getByTestId('confirm-run').click();
    await expect(page.getByTestId('delivery-note-quote-link')).toHaveText(quoteNumber);

    await page.getByTestId('delivery-note-quote-link').click();
    await expect(page).toHaveURL(new RegExp(`${quoteUrl}$`));
    await expect(page.getByTestId('quote-delivery')).toContainText(/Livré|Delivered/);
    await expect(page.getByTestId('quote-delivery-note-0')).toContainText(/Validé|Validated/);
    await expect(page.getByTestId('quote-delivery-line-0')).toContainText(`Livraison ${run}`);
    await expect(page.getByTestId('document-action-invoice')).toHaveCount(0);
    await expect(page.getByTestId('document-action-deposit')).toHaveCount(0);
    // Nothing is left to deliver: the note took the whole line.
    await expect(page.getByTestId('document-action-deliver')).toHaveCount(0);
    expect(await wcagViolations(page)).toEqual([]);
  } finally {
    await retire(page, customerNumber);
  }
});
