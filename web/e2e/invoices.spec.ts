// SPDX-License-Identifier: AGPL-3.0-or-later
import { readFileSync } from 'node:fs';
import { expect, type Page, test } from '@playwright/test';
import { inACompany, OPERATOR_PASSWORD, signIn } from './session';
import { toast } from './toast';
import { wcagViolations } from './axe';
import { choose, expectFacetCount } from './select';
import { aProduct, forget, stockKept } from './catalogue';

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

test('a line is discounted by an amount, worked out as it is typed and kept as an amount', async ({
  page,
}) => {
  const run = Date.now().toString(36).toUpperCase();
  const customerNumber = `E2E-INVA-${run}`;
  await signIn(page);
  await inACompany(page, CSRF);
  await createCustomer(page, customerNumber);
  try {
    await page.goto('/invoices/new');
    await page.getByTestId('invoice-customer').fill(customerNumber);
    await page.getByRole('option', { name: new RegExp(`^${customerNumber} · `) }).click();
    await page.getByTestId('line-0-description').fill('Conseil, deux jours');
    await page.getByTestId('line-0-quantity').fill('2');
    await page.getByTestId('line-0-price').fill('500');
    await choose(page, 'line-0-taxes', /19/);
    await page.getByTestId('line-0-discount-kind').click();
    await expect(page.getByTestId('line-0-discount-kind')).toHaveText('TND');
    // The button pressed belongs to the field it replaced: the new field takes the focus, named by its unit.
    await expect(page.getByTestId('line-0-discount')).toBeFocused();
    await expect(page.getByTestId('line-0-discount')).toHaveAccessibleName('Remise en TND');
    await page.getByTestId('line-0-discount').fill('150');
    await expect(page.getByTestId('line-0-net')).toHaveText('850,000');
    await expect(page.getByTestId('line-0-total')).toHaveText('1 011,500');
    // What the discount saves is said as it is typed, and again once saved.
    await expect(page.getByTestId('invoice-savings')).toHaveText('150,000');
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('document-action-save').click();

    await expect(page).toHaveURL(/\/invoices\/[0-9a-f-]{36}$/);
    await page.reload();
    await expect(page.getByTestId('line-0-discount-kind')).toHaveText('TND');
    await expect(page.getByTestId('line-0-discount')).toHaveValue('150,000');
    await expect(page.getByTestId('invoice-totals')).toContainText('850,000');
    await expect(page.getByTestId('invoice-savings')).toHaveText('150,000');
  } finally {
    await retire(page, customerNumber);
  }
});

test('a credit note gives back its share of a line discount, shown and not typed, and takes no discount of its own', async ({
  page,
}) => {
  const run = Date.now().toString(36).toUpperCase();
  const customerNumber = `E2E-INVC-${run}`;
  await signIn(page);
  await inACompany(page, CSRF);
  await createCustomer(page, customerNumber);
  try {
    // Ten screws at 100 with 50 off the line, issued; then a credit note of it, drafted whole.
    const credit = await page.evaluate(
      async ([csrf, wanted]) => {
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
        const listed = (await (
          await fetch(`${base}/customers?q=${encodeURIComponent(wanted)}`)
        ).json()) as { member: { id: string; number: string }[] };
        const customer = listed.member.find((row) => row.number === wanted);
        const options = (await (await fetch(`${base}/invoice-options`)).json()) as {
          units: { id: string; code: string }[];
        };
        const unit = options.units.find((each) => each.code === 'C62') ?? options.units[0];
        const invoice = await send('/invoices', {
          customerId: customer?.id,
          establishmentId: null,
          documentTaxComponentIds: [],
          lines: [
            {
              description: 'Vis',
              quantity: '10',
              unitId: unit?.id,
              unitPriceNet: '100',
              discountAmount: '50',
              taxComponentIds: [],
            },
          ],
        });
        await send(`/invoices/${invoice.id}/issue`, null);
        return (await send(`/invoices/${invoice.id}/credit-notes`, { creditNoteReason: 'Retour' }))
          .id;
      },
      [CSRF, customerNumber] as const,
    );

    await page.goto(`/invoices/${credit}`);
    await expect(page.getByTestId('line-0-discount')).toHaveValue('50,000');
    await expect(page.getByTestId('line-0-discount')).not.toBeEditable();
    await expect(page.getByTestId('line-0-discount-kind')).toBeDisabled();
    await expect(page.getByTestId('line-0-discount-share')).toHaveText(
      'Part de la remise de la ligne facturée',
    );
    await expect(page.getByTestId('field-discountAmount')).toHaveCount(0);

    // Three of the ten come back: the API gives back three tenths of the 50, whatever the field held.
    await page.getByTestId('line-0-quantity').fill('3');
    await page.getByTestId('document-action-save').click();
    await expect(page.getByTestId('line-0-discount')).toHaveValue('15,000');
    await expect(page.getByTestId('line-0-net')).toHaveText('-285,000');
    await page.getByTestId('line-0-discount-share').scrollIntoViewIfNeeded();
    await page.screenshot({ path: test.info().outputPath('credit-note-line-discount-share.png') });
    expect(await wcagViolations(page)).toEqual([]);

    // The draft is not kept: the run leaves nothing to credit in the shared company.
    await page.evaluate(
      async ([csrf, id]) => {
        const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
        const response = await fetch(`/api/companies/${me.company.id}/invoices/${id}/cancel`, {
          method: 'POST',
          headers: { 'content-type': 'application/json', 'csrf-token': csrf },
          body: 'null',
        });
        if (!response.ok) throw new Error(`cancelling the credit note answered ${response.status}`);
      },
      [CSRF, credit] as const,
    );
  } finally {
    await retire(page, customerNumber);
  }
});

test('a line says what is on hand where the invoice is made, and what the invoice would leave', async ({
  page,
}) => {
  const reference = `E2E-STK-${Date.now().toString(36).toUpperCase()}`;
  await signIn(page);
  await inACompany(page, CSRF);
  const productId = await aProduct(page, reference);
  try {
    await stockKept(page, productId, true);
    // Received on a shelf of the main establishment, which a new invoice is made at.
    await page.evaluate(
      async ([csrf, id]) => {
        const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
        const base = `/api/companies/${me.company.id}`;
        const establishments = (await (await fetch(`${base}/establishments`)).json()) as {
          id: string;
          isDefault: boolean;
        }[];
        const main = establishments.find((each) => each.isDefault)!.id;
        const locations = (await (await fetch(`${base}/stock-locations`)).json()) as {
          id: string;
          establishmentId: string;
        }[];
        const received = await fetch(`${base}/stock-movements`, {
          method: 'POST',
          headers: {
            'content-type': 'application/json',
            'csrf-token': csrf,
            'idempotency-key': crypto.randomUUID(),
          },
          body: JSON.stringify({
            operation: 'receive',
            productId: id,
            locationId: locations.find((each) => each.establishmentId === main)!.id,
            quantity: '5',
          }),
        });
        if (!received.ok) throw new Error(`receiving answered ${received.status}`);
      },
      [CSRF, productId] as const,
    );

    await page.goto('/invoices/new');
    await page.getByTestId('line-0-product').fill(reference);
    await page.getByRole('option', { name: new RegExp(`^${reference} · `) }).click();
    await expect(page.getByTestId('line-0-stock')).toHaveText(
      /En stock\s: 5 .+ · reste 4 après ce document|In stock: 5 .+ · 4 left after this document/,
    );
    await page.getByTestId('line-0-quantity').fill('7');
    await expect(page.getByTestId('line-0-stock')).toContainText(/-2 /);
    await expect(page.getByTestId('line-0-stock')).toHaveClass(/text-error/);
    expect(await wcagViolations(page)).toEqual([]);
  } finally {
    await stockKept(page, productId, false);
    await forget(page, [productId]);
  }
});

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
    // France's law asks what the operations are; Tunisia's does not, so a hand-written line issues here unasked.
    await expect(page.getByTestId('field-operationCategory')).toHaveCount(0);
    await page.getByTestId('line-0-description').fill('Conseil, deux jours');
    await page.getByTestId('line-0-quantity').fill('2');
    await page.getByTestId('line-0-price').fill('500');
    await choose(page, 'line-0-taxes', /19/);
    // Worked out as it is typed, before anything is saved (docs/SPEC.md § 7, the live line figures).
    await expect(page.getByTestId('line-0-net')).toHaveText('1 000,000');
    await expect(page.getByTestId('line-0-total')).toHaveText('1 190,000');
    await expect(page.getByTestId('invoice-totals-note')).toContainText(
      /calculés à l’instant|worked out just now/,
    );
    await page.getByTestId('line-0-toggle').click();
    await expect(
      page.getByTestId('line-0-details').locator('[data-testid^="line-0-figure-tax-"]'),
    ).toHaveText(['190,000']);
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('document-action-save').click();

    await expect(page).toHaveURL(/\/invoices\/[0-9a-f-]{36}$/);
    const invoiceUrl = page.url();
    await expect(page.getByTestId('invoice-totals')).toContainText('1 000,000');
    await expect(page.getByTestId('invoice-totals')).toContainText('190,000');
    // A draft's PDF is a preview, rarely wanted: behind « ⋮ ».
    await page.getByTestId('document-more').click();
    const draftPdf = await download(
      page,
      (await page.getByTestId('document-menu-pdf').getAttribute('href')) ?? '',
    );
    await page.keyboard.press('Escape');
    expect([draftPdf.status, draftPdf.magic]).toEqual([200, '%PDF-']);

    // E runs the state's next step, and asks first exactly as the button does.
    await outOfFields(page);
    await page.keyboard.press('e');
    // The question says what issuing will do: the number the draft takes, its total, who it is for. The total is what
    // the invoice will ask for, the timbre fiscal's dinar with it.
    await expect(page.getByTestId('confirm-message')).toContainText('1 191,000');
    await expect(page.getByTestId('confirm-message')).toContainText(/\d{4}/);
    await page.getByTestId('confirm-run').click();
    await expect(page.getByTestId('document-action-issue')).toHaveCount(0);
    await expect(page.getByTestId('invoice-status')).toContainText(/Émise|Issued/);
    const invoiceNumber = ((await page.getByTestId('invoice-title').textContent()) ?? '').trim();
    expect(invoiceNumber).toMatch(/\d{4}/);
    // An issued invoice's lines are read, never fields drawn disabled.
    await expect(page.getByTestId('line-0-quantity')).toHaveText('2');
    await expect(page.getByTestId('line-0-net')).toHaveText('1 000,000');
    await expect(page.getByTestId('invoice-lines').locator('input')).toHaveCount(0);
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
    // The page's two-column sections crushed the dialog's fields into ~230 px, cutting the day to « /2026 », and the
    // way out read « Garder le paiement » with no payment yet to keep (visual audit 2026-10-06, V-27).
    // A field spanning two columns, the notes, made a second column of its own in the one-column dialog grid.
    await expect(page.getByTestId('field-wrapper-notes')).toBeVisible();
    // The dialog grows into place, and a box measured mid-way reads narrower: its first field takes the cursor only
    // once it has finished opening, so the widths are read after that.
    await expect(page.getByTestId('field-date')).toBeFocused();
    for (const field of ['date', 'amount', 'method', 'reference', 'notes']) {
      const width = await page
        .getByTestId(`field-wrapper-${field}`)
        .evaluate((wrapper) => wrapper.getBoundingClientRect().width);
      expect(width, `the dialog’s ${field} takes the dialog’s width`).toBeGreaterThan(300);
    }
    await expect(page.getByTestId('payment-cancel')).toHaveText(/Fermer/);
    // The dialog puts the cursor in its first field once it has finished opening; filling before that sent the
    // reference into the day on a slow runner.
    await expect(page.getByTestId('field-date')).toBeFocused();
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
    await expect(toast(page)).toContainText('est supprimé');
    await expect(page.getByTestId('invoice-payments-none')).toBeVisible();
    await expect(page.getByTestId('invoice-status')).toContainText(/Émise|Issued/);
    await expect(page.getByTestId('invoice-amount-due')).toHaveText(due);

    // A cheque is not money (docs/SPEC.md § 7, 2026-09-21 18:40): the invoice stays due while it is held or deposited,
    // and one that comes back unpaid leaves a trace and no payment. Cashing is certified by the API's functional
    // test and the panel's spec: a cashed cheque's payment is kept, which would end this run's credit note below.
    await page.getByTestId('instrument-receive').click();
    await page.getByTestId('field-number').fill(`CHQ ${run}`);
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('instrument-save').click();
    await expect(toast(page)).toContainText('Le chèque ou la traite est reçu.');
    await expect(page.getByTestId('invoice-instruments')).toContainText(`CHQ ${run}`);
    await expect(page.getByTestId('invoice-amount-due')).toHaveText(due);
    // Due today, so it is already « à surveiller » (docs/SPEC.md § 7, 2026-09-21 18:50: due this week); back to the invoice after.
    await page.goto('/watch/invoices.instruments_due');
    await expect(
      page
        .getByTestId('watch-table')
        .getByRole('row')
        .filter({ hasText: `CHQ ${run}` }),
    ).toHaveCount(1);
    expect(await wcagViolations(page)).toEqual([]);
    await expect(page.getByTestId('watch-elsewhere')).toHaveAttribute('href', '/instruments');
    await page.goBack();
    await expect(page.getByTestId('invoice-instruments')).toContainText(`CHQ ${run}`);
    // The portfolio lists it with its customer, and its invoice number leads back to the invoice. It is reached from
    // the invoices list's « ⋮ », as a person would.
    await page.goto('/invoices');
    await page.getByTestId('invoices-more').click();
    await page.getByTestId('invoices-portfolio').click();
    await expect(page).toHaveURL(/\/instruments$/);
    // Found by its customer, this run's own: the cheque's number waits in the columns menu (audit V-20).
    const held = page
      .getByTestId('portfolio-table')
      .getByRole('row')
      .filter({ hasText: `Client ${customerNumber}` });
    // Filters combine here too (docs/SPEC.md § 7, 2026-10-06): a cheque that is held or open, due after a day in the
    // far future, is not this one, and taking the interval's chip off brings it back. Narrowed first, since every run
    // leaves its unpaid cheque in the shared company and this one would sort onto another page.
    await choose(page, 'list-facet-kind', /Chèque|Cheque/);
    await choose(page, 'list-facet-status', /En portefeuille|In portfolio/);
    await choose(page, 'list-facet-status', /En cours|Open/);
    await expect(held).toHaveCount(1);
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('list-filters').click();
    await page.getByTestId('portfolio-table-filter-panel-dueOn-from').fill('2999-01-01');
    await expect(page.getByTestId('list-chip-dueOn:range')).toBeVisible();
    await expect(held).toHaveCount(0);
    await page.getByTestId('list-chip-dueOn:range').getByRole('button').click();
    await expect(held).toHaveCount(1);
    await held.getByRole('link').first().click();
    await expect(page).toHaveURL(invoiceUrl);
    // Every step on a cheque asks through the shared confirmation, deposit included (audit 2026-10-06, C-8).
    await page.locator('[data-testid^="instrument-"][data-testid$="-deposit"]').click();
    await expect(page.getByTestId('confirm-kind')).toHaveAttribute('data-kind', 'definitif');
    await page.getByTestId('confirm-run').click();
    await expect(toast(page)).toContainText('remis à l’encaissement');
    await page.locator('[data-testid^="instrument-"][data-testid$="-unpaid"]').click();
    await page.getByTestId('confirm-run').click();
    await expect(toast(page)).toContainText('marqué impayé');
    await expect(page.getByTestId('invoice-instruments')).toContainText('Impayé');
    await expect(page.getByTestId('invoice-payments-none')).toBeVisible();
    await expect(page.getByTestId('invoice-status')).toContainText(/Émise|Issued/);
    await expect(page.getByTestId('invoice-amount-due')).toHaveText(due);
    // What came back unpaid promises nothing, so another can be received and, still held, taken out again.
    await page.getByTestId('instrument-receive').click();
    await page.getByTestId('instrument-save').click();
    await expect(toast(page)).toContainText('Le chèque ou la traite est reçu.');
    // Taking a held one out asks nothing: the toast takes it back, « Récemment supprimés » for a day.
    await page.locator('[data-testid^="instrument-"][data-testid$="-delete"]').click();
    await expect(page.getByTestId('confirm-run')).toHaveCount(0);
    await expect(toast(page)).toContainText('est supprimé');
    await expect(page.locator('[data-testid^="instrument-"][data-testid$="-status"]')).toHaveCount(
      1,
    );
    expect(await wcagViolations(page)).toEqual([]);

    // From its list, an issued invoice opens as a sheet over it, which offers the record's own actions: « Encaisser »
    // there asks for the payment over the list, without leaving it.
    await page.goto('/invoices');
    await page.getByTestId('list-filter').fill(invoiceNumber);
    await page.getByTestId('invoices-table').getByRole('link', { name: invoiceNumber }).click();
    const sheet = page.getByTestId('invoice-sheet');
    await expect(sheet).toBeVisible();
    await expect(page).toHaveURL(/\/invoices\?.*open=/);
    await expect(page.getByTestId('invoice-sheet-title')).toHaveText(invoiceNumber);
    // The same amount the record showed, whatever the locale does with its separators.
    const sheetDue = (await page.getByTestId('invoice-sheet-due').textContent()) ?? '';
    expect(sheetDue.replace(/\D/g, '')).toContain(due.replace(/\D/g, ''));
    await expect(page.getByTestId('invoices-table')).toBeVisible();
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('invoice-sheet-close').click();
    await expect(sheet).toHaveCount(0);
    await page.getByTestId('invoices-table').getByRole('link', { name: invoiceNumber }).click();
    await sheet.getByTestId('document-action-record-payment').click();
    await expect(page.getByTestId('payment-dialog-title')).toBeVisible();
    await expect(page).toHaveURL(/\/invoices\?.*open=/);
    await page.keyboard.press('Escape');
    await expect(page.getByTestId('payment-dialog-title')).toHaveCount(0);
    await page.goto(invoiceUrl);
    await expect(page.getByTestId('invoice-status')).toBeVisible();

    // On a phone the status line wraps, and no line of it starts with a « · » (audit 2026-10-06 V-3): a fact that
    // starts a line has its separator clipped, one past the line's start (`.twes-dot-list`).
    const wide = page.viewportSize()!;
    await page.setViewportSize({ width: 390, height: 844 });
    await expect
      .poll(() =>
        page.evaluate(() => {
          const line = document.querySelector('[data-testid="invoice-status"]')!;
          const left = line.getBoundingClientRect().left;
          const facts = [...line.children].map((fact) => fact.getBoundingClientRect());
          // The first fact starts the line and has no « · »; any other at the line's start would show its own.
          return (
            facts.length > 1 && facts.slice(1).every((fact) => Math.abs(fact.left - left) >= 1)
          );
        }),
      )
      .toBe(true);
    await page.setViewportSize(wide);

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
    // Each status chip says how many it would list, as the API counts them (docs/SPEC.md § 7, 2026-09-26): this run's
    // invoice is settled, so « Soldée » counts at least it.
    await expectFacetCount(page, 'list-facet-status-paid', /^[1-9]\d*$/);

    // Filters combine (docs/SPEC.md § 7, 2026-10-06): « Soldée » or « Brouillon » keeps this settled invoice, a chip names
    // each, and an interval of the issue day that begins tomorrow takes it out again until its chip is removed.
    await choose(page, 'list-facet-status', /Soldée|Settled/);
    await choose(page, 'list-facet-status', /Brouillon|Draft/);
    await expect(page.getByTestId('list-chip-status:paid')).toBeVisible();
    await expect(page.getByTestId('list-chip-status:draft')).toBeVisible();
    await expect(page.getByTestId('invoices-table')).toContainText(invoiceNumber);
    await page.getByTestId('list-filters').click();
    const tomorrow = new Date(Date.now() + 2 * 86_400_000).toISOString().slice(0, 10);
    await page.getByTestId('invoices-table-filter-panel-issueDate-from').fill(tomorrow);
    await expect(page.getByTestId('list-chip-issueDate:range')).toBeVisible();
    await expect(page.getByTestId('invoices-table')).not.toContainText(invoiceNumber);
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('list-chip-issueDate:range').getByRole('button').click();
    await expect(page.getByTestId('invoices-table')).toContainText(invoiceNumber);
    await page.getByTestId('list-chips-clear').click();
    await expect(page.getByTestId('list-chips')).toHaveCount(0);

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

test(
  'on a phone a new invoice is saved from its bar, not only from « ⋮ »',
  { tag: '@phone' },
  async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await signIn(page);
    await inACompany(page, CSRF);
    await page.goto('/invoices/new');

    const save = page.getByTestId('document-action-save');
    await expect(save).toBeVisible();
    await expect(save).toHaveAccessibleName('Enregistrer le brouillon');
  },
);

test(
  'on a phone the fields of a line stand apart, so no label is drawn on the field above',
  { tag: '@phone' },
  async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await signIn(page);
    await inACompany(page, CSRF);
    await page.goto('/invoices/new');
    await expect(page.getByTestId('line-0')).toBeVisible();

    const gaps = await page
      .getByTestId('line-0')
      .locator('.mat-mdc-text-field-wrapper')
      .evaluateAll((boxes) =>
        boxes
          .map((box) => box.getBoundingClientRect())
          .filter((box) => box.height > 0)
          .sort((a, b) => a.top - b.top)
          .flatMap((box, index, all) => (index === 0 ? [] : [box.top - all[index - 1].bottom])),
      );
    expect(gaps.length).toBeGreaterThan(3);
    expect(Math.min(...gaps)).toBeGreaterThanOrEqual(12);
  },
);

/** A customer of the run's own and two drafts for it, through the API, so the list holds exactly what is selected. */
async function twoDrafts(
  page: Page,
  number: string,
): Promise<{ customer: string; invoices: string[] }> {
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
      const draft = () =>
        send('/invoices', {
          customerId: customer.id,
          establishmentId: null,
          lines: [
            {
              description: 'Réglage du tour',
              quantity: '1',
              unitId: unit?.id,
              unitPriceNet: '100',
              discountRate: null,
              taxComponentIds: [],
            },
          ],
        });
      const first = await draft();
      const second = await draft();
      return { customer: customer.id, invoices: [first.id, second.id] };
    },
    [CSRF, number] as const,
  );
}

/** Abandons the run's drafts and deactivates its customer. */
async function tidyDrafts(
  page: Page,
  made: { customer: string; invoices: string[] },
): Promise<void> {
  const statuses = await page.evaluate(
    async ([csrf, ids]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}`;
      const headers = { 'content-type': 'application/json', 'csrf-token': csrf };
      const answered: number[] = [];
      for (const invoice of ids.invoices) {
        const cancelled = await fetch(`${base}/invoices/${invoice}/cancel`, {
          method: 'POST',
          headers,
          body: 'null',
        });
        answered.push(cancelled.status);
      }
      // The write shape has no id: a body naming one is refused.
      const { id: _, ...fields } = (await (
        await fetch(`${base}/customers/${ids.customer}`)
      ).json()) as Record<string, unknown>;
      const retired = await fetch(`${base}/customers/${ids.customer}`, {
        method: 'PUT',
        headers,
        body: JSON.stringify({ ...fields, isActive: false }),
      });
      answered.push(retired.status);
      return answered;
    },
    [CSRF, made] as const,
  );
  expect(statuses).toEqual([200, 200, 200]);
}

test('invoices are selected on their page, counted in a bar that stands for the toolbar, and let go', async ({
  page,
}) => {
  await signIn(page);
  await inACompany(page, CSRF);
  const number = `SEL-${Date.now()}`;
  const made = await twoDrafts(page, number);
  try {
    // Narrowed to the run's customer by its picker: a draft has no customer snapshot for the words to find yet.
    await page.goto(`/invoices?customer=${made.customer}`);
    const boxes = page.locator('[data-testid^="list-select-row-"]');
    await expect(boxes).toHaveCount(2);
    // A draft has no number: its box is named by the customer it is drafted for.
    await expect(boxes.first().locator('input')).toHaveAccessibleName(
      `Sélectionner le brouillon pour Client ${number}`,
    );

    // The bar stands where the toolbar stood, as tall: the next box is still under the pointer.
    const before = await boxes.nth(1).boundingBox();
    await boxes.nth(0).locator('input').check();
    const bar = page.getByTestId('list-selection');
    await expect(bar).toBeVisible();
    expect((await boxes.nth(1).boundingBox())?.y).toBe(before?.y);
    await expect(page.getByTestId('list-selection-count')).toHaveText('1 facture sélectionnée');
    // The words and filters wait behind the bar; the row's own actions wait too.
    await expect(page.getByTestId('list-filter')).toHaveCount(0);
    await expect(page.getByTestId(`row-more-${made.invoices[0]}`)).toBeDisabled();
    expect(await wcagViolations(page)).toEqual([]);

    await page.getByTestId('list-select-page').locator('input').check();
    await expect(page.getByTestId('list-selection-count')).toHaveText('2 factures sélectionnées');

    // The selection leaves as a file of just those rows: the API finds both, so it downloads without a question.
    const download = page.waitForEvent('download');
    await page.getByTestId('invoices-bulk-export').click();
    await page.getByTestId('invoices-bulk-export-csv').click();
    const stepUp = page.getByTestId('step-up-password');
    const asked = await Promise.race([
      download.then(() => false),
      stepUp.waitFor({ state: 'visible' }).then(
        () => true,
        () => false,
      ),
    ]);
    if (asked) {
      await stepUp.fill(OPERATOR_PASSWORD);
      await page.getByTestId('step-up-confirm').click();
    }
    const file = await download;
    expect(file.suggestedFilename()).toBe('invoices.csv');
    const lines = readFileSync(await file.path(), 'utf8')
      .split('\n')
      .filter((line) => line.trim() !== '');
    expect(lines).toHaveLength(3);
    await expect(toast(page)).toContainText('2 factures exportées');
    await expect(page.getByTestId('invoice-bulk-skipped')).toHaveCount(0);
    expect(await wcagViolations(page)).toEqual([]);

    // Both are drafts, which a PDF leaves out unless asked: the dialog names them, offers their box, unticked, and
    // nothing to go on with until it is ticked.
    await page.getByTestId('invoices-bulk-pdf').click();
    await page.getByTestId('invoices-bulk-pdf-pdf').click();
    const skipped = page.getByTestId('invoice-bulk-skipped');
    await expect(skipped.locator('li')).toHaveCount(2);
    await expect(skipped).toContainText('Brouillon : pas encore numérotée');
    const drafts = page.getByTestId('invoice-bulk-include-drafts').locator('input');
    await expect(drafts).not.toBeChecked();
    await expect(page.getByTestId('invoice-bulk-include-cancelled')).toHaveCount(0);
    await expect(page.getByTestId('invoice-bulk-go')).toHaveCount(0);
    await expect(page.getByTestId('invoice-bulk-cancel')).toHaveText('Fermer');
    expect(await wcagViolations(page)).toEqual([]);

    await drafts.check();
    await expect(skipped.locator('li')).toHaveCount(0);
    const printed = page.waitForEvent('download');
    await page.getByTestId('invoice-bulk-go').click();
    const pdf = await printed;
    expect(pdf.suggestedFilename()).toBe('invoices.pdf');
    // The two drafts rendered by Gotenberg and merged into one document of two pages at least, one invoice each.
    const bytes = readFileSync(await pdf.path());
    expect(bytes.subarray(0, 5).toString('latin1')).toBe('%PDF-');
    expect(bytes.toString('latin1').match(/\/Type\s*\/Page(?!s)/g)?.length).toBeGreaterThanOrEqual(
      2,
    );
    await expect(toast(page)).toContainText('2 factures en PDF');

    await page.keyboard.press('Escape');
    await expect(bar).toHaveCount(0);
    await expect(page.getByTestId('list-select-page').locator('input')).toBeFocused();
    // What narrowed the list is still there.
    await expect(page.getByTestId('list-chips')).toContainText(`Client ${number}`);

    // On a phone the cards carry no box until « Sélectionner » is pressed.
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.getByTestId('list-cards')).toBeVisible();
    await expect(boxes).toHaveCount(0);
    await page.getByTestId('list-select-mode').click();
    await expect(page.getByTestId('list-selection-count')).toHaveText(
      'Aucune facture sélectionnée',
    );
    await boxes.nth(1).locator('input').check();
    await expect(page.getByTestId('list-selection-count')).toHaveText('1 facture sélectionnée');
    // The bar is narrow there: its actions go under the count rather than squeeze it, so the count reads on one line
    // with nothing spilling out of its box, and they stay inside the screen.
    const fits = await page.getByTestId('list-selection-count').evaluate((count) => {
      const line = parseFloat(getComputedStyle(count).lineHeight);
      return (
        count.scrollWidth <= count.clientWidth && count.getBoundingClientRect().height < line * 1.5
      );
    });
    expect(fits).toBe(true);
    const count = await page.getByTestId('list-selection-count').boundingBox();
    for (const action of ['invoices-bulk-export', 'invoices-bulk-pdf', 'invoices-bulk-pay']) {
      const box = await page.getByTestId(action).boundingBox();
      expect(box!.y >= count!.y + count!.height || box!.x >= count!.x + count!.width, action).toBe(
        true,
      );
      expect(box!.x + box!.width, action).toBeLessThanOrEqual(390);
    }
    await page.getByTestId('list-selection-clear').click();
    await expect(boxes).toHaveCount(0);
    await expect(page.getByTestId('list-select-mode')).toBeFocused();
  } finally {
    await tidyDrafts(page, made);
  }
});

test('the invoices selected are cashed at once, each for what it owes, and those that cannot be are named', async ({
  page,
}) => {
  await signIn(page);
  await inACompany(page, CSRF);
  const number = `CASH-${Date.now()}`;
  const made = await twoDrafts(page, number);
  try {
    // Issued, so each owes its total; an issued invoice stays in the shared company, as every issued one in these runs.
    const issued = await page.evaluate(
      async ([csrf, ids]) => {
        const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
        const answered: number[] = [];
        for (const id of ids) {
          const response = await fetch(`/api/companies/${me.company.id}/invoices/${id}/issue`, {
            method: 'POST',
            headers: { 'content-type': 'application/json', 'csrf-token': csrf },
            body: 'null',
          });
          answered.push(response.status);
        }
        return answered;
      },
      [CSRF, made.invoices] as const,
    );
    expect(issued).toEqual([200, 200]);

    await page.goto(`/invoices?customer=${made.customer}`);
    await expect(page.locator('[data-testid^="list-select-row-"]')).toHaveCount(2);
    await page.getByTestId('list-select-page').locator('input').check();
    await page.getByTestId('invoices-bulk-pay').click();

    // The day, method and reference come first; under them what each invoice receives, and the total.
    const rows = page.getByTestId('invoice-bulk-pay-rows');
    await expect(rows.locator('li')).toHaveCount(2);
    await expect(page.getByTestId('invoice-bulk-pay-total')).toContainText('Total encaissé');
    await expect(page.getByTestId('invoice-bulk-pay-go')).toHaveText('Encaisser les 2 factures');
    // The dialog puts the cursor in its first field, the day, once it has finished opening; filling the reference
    // before that sent it into the day on a slow runner, and the invalid day held the cashing back.
    await expect(page.getByTestId('field-date')).toBeFocused();
    await page.getByTestId('field-reference').fill('VIR-E2E');
    expect(await wcagViolations(page)).toEqual([]);

    await page.getByTestId('invoice-bulk-pay-go').click();
    await expect(toast(page)).toContainText('2 factures encaissées');
    await expect(page.getByTestId('invoice-bulk-pay-go')).toHaveCount(0);
    // The list is read again: both are settled.
    await expect(page.locator('td[data-column="status"]')).toHaveText(['Soldée', 'Soldée']);

    // Asked again, nothing is left to cash: each is named with why, and there is nothing to confirm.
    await page.getByTestId('invoices-bulk-pay').click();
    await expect(page.getByTestId('invoice-bulk-pay-skipped').locator('li')).toHaveCount(2);
    await expect(page.getByTestId('invoice-bulk-pay-skipped')).toContainText(
      'Soldée : plus rien n’est dû',
    );
    await expect(page.getByTestId('invoice-bulk-pay-go')).toBeDisabled();
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('invoice-bulk-pay-cancel').click();
  } finally {
    const retired = await page.evaluate(
      async ([csrf, customer]) => {
        const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
        const path = `/api/companies/${me.company.id}/customers/${customer}`;
        // The write shape has no id: a body naming one is refused.
        const { id: _, ...fields } = (await (await fetch(path)).json()) as Record<string, unknown>;
        const response = await fetch(path, {
          method: 'PUT',
          headers: { 'content-type': 'application/json', 'csrf-token': csrf },
          body: JSON.stringify({ ...fields, isActive: false }),
        });
        return response.status;
      },
      [CSRF, made.customer] as const,
    );
    expect(retired).toBe(200);
  }
});

test('an issued invoice is delivered by a note, which names it, moves no stock and is not invoiced again', async ({
  page,
}) => {
  // « Bon de livraison » on an issued invoice of goods: the draft note it opens names the invoice, carries its line,
  // and once validated the invoice reads « Livrée » and lists it, while the note offers no invoice of its own: the
  // invoice billed the goods and took them out of stock when it was issued.
  test.setTimeout(90_000);
  const run = Date.now().toString(36).toUpperCase();
  const customerNumber = `E2E-INVDL-${run}`;
  await signIn(page);
  await inACompany(page, CSRF);
  await createCustomer(page, customerNumber);
  const productId = await aProduct(page, `E2E-INVDL-${run}`);
  try {
    const invoiceId = await page.evaluate(
      async ([csrf, wanted, product]) => {
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
        const listed = (await (
          await fetch(`${base}/customers?q=${encodeURIComponent(wanted)}`)
        ).json()) as { member: { id: string; number: string }[] };
        const customer = listed.member.find((row) => row.number === wanted);
        const invoice = await send('/invoices', {
          customerId: customer?.id,
          establishmentId: null,
          supplyDate: null,
          paymentTermsDays: null,
          customerReference: null,
          notesPrinted: null,
          notesInternal: null,
          discountAmount: null,
          documentTaxComponentIds: [],
          lines: [{ productId: product, quantity: '3', unitPriceNet: '10', taxComponentIds: [] }],
        });
        await send(`/invoices/${invoice.id}/issue`, null);
        return invoice.id;
      },
      [CSRF, customerNumber, productId] as const,
    );

    await page.goto(`/invoices/${invoiceId}`);
    const invoiceNumber = (await page.getByTestId('invoice-title').textContent())?.trim() ?? '';
    expect(invoiceNumber).not.toBe('');
    await page.getByTestId('document-action-deliver').click();
    await expect(page).toHaveURL(/\/delivery-notes\/[0-9a-f-]{36}$/);
    await expect(page.getByTestId('delivery-note-invoice-link')).toHaveText(invoiceNumber);
    await expect(page.getByTestId('line-0-quantity')).toHaveValue('3');
    await page.getByTestId('document-action-validate').click();
    await page.getByTestId('confirm-run').click();
    await expect(page.getByTestId('delivery-note-invoice-link')).toHaveText(invoiceNumber);
    // Its goods were billed by the invoice: the note is not invoiced again.
    await expect(page.getByTestId('document-action-invoice')).toHaveCount(0);
    await expect(page.getByTestId('document-action-invoice-part')).toHaveCount(0);

    await page.getByTestId('delivery-note-invoice-link').click();
    await expect(page).toHaveURL(new RegExp(`/invoices/${invoiceId}$`));
    await expect(page.getByTestId('invoice-delivery')).toContainText(/Livré|Delivered/);
    await expect(page.getByTestId('invoice-delivered-by-0')).toContainText(/Validé|Validated/);
    await expect(page.getByTestId('document-action-deliver')).toHaveCount(0);
    expect(await wcagViolations(page)).toEqual([]);
  } finally {
    await retire(page, customerNumber);
    await forget(page, [productId]);
  }
});
