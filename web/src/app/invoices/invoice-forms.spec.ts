// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  applyProduct,
  defaultDocumentTaxes,
  documentTaxOptions,
  invoiceForm,
  invoiceInput,
  invoiceListRows,
  invoiceSearch,
  invoiceValues,
  INVOICES_LIST,
  lineGroup,
  pickedProduct,
  sourceLeftOf,
  linesArray,
  paymentForm,
  paymentInput,
  shownStatus,
  daysLate,
  paidShare,
  stillOwed,
  dropRefusedLineTaxes,
  figuresReady,
  lineStock,
} from './invoice-forms';
import { FormArray } from '@angular/forms';
import type { LineGroup } from './invoice-forms';
import type { CustomerOption, InvoiceOptions, InvoiceRow, ProductOption } from './invoices-types';

const options: InvoiceOptions = {
  currency: 'TND',
  currencyScale: 3,
  establishments: [
    { id: 'e0', code: 'SFAX', name: 'Sfax', isDefault: false },
    { id: 'e1', code: 'SIEGE', name: 'Siège', isDefault: true },
  ],
  units: [
    { id: 'u1', code: 'C62', name: 'Unité', decimals: 0 },
    { id: 'u2', code: 'HUR', name: 'Heure', decimals: 2 },
  ],
  taxes: [
    tax('t1', 'TVA19', 'percentage_line', 'vat', false),
    tax('f1', 'FODEC', 'percentage_line', 'levy', false),
    tax('s1', 'TIMBRE', 'fixed_document', 'stamp', true),
    tax('w1', 'RS1', 'withholding_total', 'withholding', false),
  ],
  operationCategory: false,
};

/** What the picker answers, which is all the form ever knows about a customer or a product. */
const carthage: CustomerOption = {
  id: 'k1',
  number: 'CLI-1',
  name: 'Carthage',
  excludedFamilies: [],
  defaultDiscountRate: '5',
  defaultTaxComponentIds: ['w1'],
};
const embassy: CustomerOption = {
  id: 'k2',
  number: 'CLI-2',
  name: 'Ambassade',
  excludedFamilies: ['vat', 'stamp'],
  defaultDiscountRate: null,
  defaultTaxComponentIds: [],
};
const design: ProductOption = {
  id: 'p1',
  reference: 'ART-1',
  name: 'Conception',
  unitId: 'u2',
  unitPriceNet: '1800.0000',
  defaultTaxComponentIds: ['t1', 'f1'],
  tracking: 'none',
  mainPhotoId: null,
};

function tax(
  id: string,
  code: string,
  kind: InvoiceOptions['taxes'][number]['kind'],
  family: InvoiceOptions['taxes'][number]['family'],
  isDefault: boolean,
): InvoiceOptions['taxes'][number] {
  return {
    id,
    code,
    name: code,
    kind,
    family,
    rate: kind === 'fixed_document' ? null : '1',
    amount: kind === 'fixed_document' ? '1.000' : null,
    threshold: null,
    isDefault,
  };
}

function invoice(overrides: Partial<InvoiceRow> = {}): InvoiceRow {
  return {
    id: 'i1',
    type: 'invoice',
    correctsInvoiceId: null,
    creditNoteReason: null,
    deposit: false,
    quoteId: null,
    number: 'FAC-2026-00043',
    status: 'issued',
    customerId: 'k1',
    recordedCustomerName: 'Carthage SA',
    customerName: 'Carthage',
    establishmentId: 'e1',
    issueDate: '2026-08-14',
    dueDate: '2026-09-13',
    supplyDate: null,
    paymentTermsDays: 30,
    customerReference: null,
    notesPrinted: null,
    notesInternal: null,
    discountAmount: null,
    operationCategory: null,
    vatOnDebits: null,
    documentTaxComponentIds: ['s1'],
    lines: [],
    subtotalNet: '1200.000',
    documentDiscount: '0.000',
    savings: null,
    totalNet: '1200.000',
    taxes: [],
    totalTax: '228.000',
    fixedTaxes: [],
    total: '1428.000',
    withholdings: [],
    netToPay: '1428.000',
    amountDue: '1428.000',
    amountPaid: '0.000',
    amountCredited: '0.000',
    payments: [],
    ...overrides,
  };
}

describe('invoice forms', () => {
  describe('shownStatus', () => {
    it('reads an issued or partly paid invoice past its due day as overdue', () => {
      expect(shownStatus(invoice(), '2026-09-16')).toBe('overdue');
      expect(shownStatus(invoice({ status: 'partially_paid' }), '2026-09-16')).toBe('overdue');
    });

    it('keeps the status on the due day itself and before it', () => {
      expect(shownStatus(invoice(), '2026-09-13')).toBe('issued');
      expect(shownStatus(invoice({ status: 'partially_paid' }), '2026-09-01')).toBe(
        'partially_paid',
      );
    });

    it('never reads a paid invoice, a draft or a credit note as overdue', () => {
      expect(shownStatus(invoice({ status: 'paid' }), '2027-01-01')).toBe('paid');
      expect(shownStatus(invoice({ status: 'draft', dueDate: null }), '2027-01-01')).toBe('draft');
      expect(shownStatus(invoice({ type: 'credit_note' }), '2027-01-01')).toBe('issued');
    });
  });

  // One rule for the record page's « Enregistrer un paiement » and the sheet's « Encaisser » (docs/SPEC.md § 7, 2026-09-26).
  describe('stillOwed', () => {
    it('is an issued or partly paid invoice with something left to collect', () => {
      expect(stillOwed(invoice())).toBe(true);
      expect(stillOwed(invoice({ status: 'partially_paid', amountDue: '0.500' }))).toBe(true);
    });

    it('is never a credit note, a draft, a settled or cancelled document, nor one whose due is zero', () => {
      expect(stillOwed(invoice({ type: 'credit_note' }))).toBe(false);
      expect(stillOwed(invoice({ status: 'draft' }))).toBe(false);
      expect(stillOwed(invoice({ status: 'paid', amountDue: '0.000' }))).toBe(false);
      expect(stillOwed(invoice({ status: 'cancelled' }))).toBe(false);
      expect(stillOwed(invoice({ amountDue: '0.000' }))).toBe(false);
      expect(stillOwed(invoice({ amountDue: '-0.000' }))).toBe(false);
      expect(stillOwed(null)).toBe(false);
    });
  });

  describe('daysLate', () => {
    it('counts the days since the due day, negative before it, across a month and a year', () => {
      expect(daysLate('2026-09-13', '2026-09-16')).toBe(3);
      expect(daysLate('2026-09-16', '2026-09-16')).toBe(0);
      expect(daysLate('2026-10-10', '2026-09-16')).toBe(-24);
      expect(daysLate('2026-12-30', '2027-01-02')).toBe(3);
    });

    it('counts whole days across a daylight saving change', () => {
      expect(daysLate('2026-03-28', '2026-03-30')).toBe(2);
      expect(daysLate('2026-10-24', '2026-10-26')).toBe(2);
    });
  });

  describe('paidShare', () => {
    it('is the part of the total paid, in whole percent, held between 0 and 100', () => {
      expect(paidShare(invoice({ amountPaid: '714.000' }))).toBe(50);
      expect(paidShare(invoice({ amountPaid: '0.000' }))).toBe(0);
      expect(paidShare(invoice({ amountPaid: '1428.000' }))).toBe(100);
      expect(paidShare(invoice({ amountPaid: '2000.000' }))).toBe(100);
      expect(paidShare(invoice({ total: '0.000', amountPaid: '0.000' }))).toBe(0);
    });
  });

  describe('invoiceSearch', () => {
    const query = {
      pageIndex: 0,
      pageSize: 25,
      query: '',
      filters: {} as Record<string, string>,
      sort: null,
    };

    it('asks for the page the list shows, numbered from one', () => {
      expect(invoiceSearch({ ...query, pageIndex: 2, pageSize: 50 })).toMatchObject({
        page: 3,
        itemsPerPage: 50,
      });
    });

    it('carries overdue through as a status, which the API answers on the company’s day', () => {
      // It is not a status a document holds; filtering on screen would filter one page instead of the list.
      expect(invoiceSearch({ ...query, filters: { status: 'overdue' } }).status).toEqual([
        'overdue',
      ]);
      expect(invoiceSearch({ ...query, filters: { status: 'paid' } }).status).toEqual(['paid']);
      expect(invoiceSearch({ ...query, filters: { status: 'nonsense' } }).status).toEqual([]);
    });

    it('combines statuses, kinds and customers, leaving out what the list does not offer', () => {
      const search = invoiceSearch({
        ...query,
        filters: {
          status: 'overdue,draft,nonsense',
          type: 'invoice,credit_note,deposit',
          customer:
            '01a11304-e8c9-75fd-a04c-ec517b121291,nope,01a11304-e8c9-75fd-a04c-ec517b121292',
        },
      });
      expect(search.status).toEqual(['draft', 'overdue']);
      expect(search.documentType).toEqual(['invoice', 'deposit', 'credit_note']);
      expect(search.customerIds).toEqual([
        '01a11304-e8c9-75fd-a04c-ec517b121291',
        '01a11304-e8c9-75fd-a04c-ec517b121292',
      ]);
    });

    it('keeps the ends of an interval that are a day or an amount, and drops the rest', () => {
      expect(
        invoiceSearch({
          ...query,
          filters: {
            'issueDate.from': '2026-02-30',
            'issueDate.to': '2026-03-31',
            'dueDate.from': '2026-01-01',
            'totalGross.min': '12.5',
            'totalGross.max': '-1',
            'amountDue.max': '1e3',
          },
        }).intervals,
      ).toEqual({
        'issueDate.to': '2026-03-31',
        'dueDate.from': '2026-01-01',
        'totalGross.min': '12.5',
      });
    });

    it('passes the kind of document and the sort the column names', () => {
      expect(invoiceSearch({ ...query, filters: { type: 'credit_note' } }).documentType).toEqual([
        'credit_note',
      ]);
      expect(invoiceSearch({ ...query, filters: { type: 'nonsense' } }).documentType).toEqual([]);
      expect(
        invoiceSearch({ ...query, sort: { column: 'issueDate', direction: 'desc' } }).order,
      ).toEqual({ key: 'issueDate', direction: 'desc' });
      // A column the API cannot sort by is left to the API's own order rather than sent as one it would refuse.
      expect(
        invoiceSearch({ ...query, sort: { column: 'total', direction: 'asc' } }).order,
      ).toBeNull();
    });
  });

  it('names each row by the issued name, else today’s customer, with its shown status', () => {
    const rows = invoiceListRows(
      [
        invoice(),
        invoice({ id: 'i2', status: 'draft', recordedCustomerName: null, dueDate: null }),
      ],
      '2026-09-16',
    );
    expect(rows.map((row) => [row.customer, row.shown])).toEqual([
      ['Carthage SA', 'overdue'],
      ['Carthage', 'draft'],
    ]);
  });

  it('filters the list by the shown status, overdue counted apart from issued', () => {
    const filter = INVOICES_LIST.filters?.find((each) => each.id === 'status');
    expect(filter?.options.map((option) => option.value)).toEqual([
      'draft',
      'issued',
      'overdue',
      'partially_paid',
      'paid',
      'cancelled',
    ]);
    const [row] = invoiceListRows([invoice()], '2026-09-16');
    expect(row && filter?.value(row)).toBe('overdue');
  });

  it('tells a deposit from an invoice in the kind each row shows and the list filters by', () => {
    const filter = INVOICES_LIST.filters?.find((each) => each.id === 'type');
    expect(filter?.options.map((option) => [option.value, option.label])).toEqual([
      ['invoice', 'invoices.types.invoice'],
      ['deposit', 'invoices.types.deposit'],
      ['credit_note', 'invoices.types.credit_note'],
    ]);
    const rows = invoiceListRows(
      [invoice(), invoice({ deposit: true }), invoice({ type: 'credit_note' })],
      '2026-09-16',
    );
    expect(rows.map((row) => [row.kind, filter?.value(row)])).toEqual([
      ['invoice', 'invoice'],
      ['deposit', 'deposit'],
      ['credit_note', 'credit_note'],
    ]);
  });

  it('gives a credit note no due day nor anything owed on a card, which only an issued invoice has', () => {
    const column = (id: string) => INVOICES_LIST.columns.find((candidate) => candidate.id === id);
    const [issued, credit, draft] = invoiceListRows(
      [
        invoice({ status: 'issued' }),
        invoice({ type: 'credit_note', status: 'issued' }),
        invoice({ status: 'draft', dueDate: null }),
      ],
      '2026-09-16',
    );
    for (const id of ['dueDate', 'amountDue']) {
      expect(column(id)?.shown?.(issued!)).toBe(true);
      expect(column(id)?.shown?.(credit!)).toBe(false);
      expect(column(id)?.shown?.(draft!)).toBe(false);
    }
  });

  it('offers the establishments, keeping the one a document already names, and asks the customer elsewhere', () => {
    const form = invoiceForm(options, invoice({ customerId: 'gone', establishmentId: 'closed' }));
    const fields = form.sections.flatMap((section) => section.fields);
    const establishment = fields.find((field) => field.id === 'establishmentId');
    expect(establishment?.options?.map((option) => option.value)).toContain('closed');
    // The customer is a picker on the page, never a field in this descriptor: a book is not a dropdown.
    expect(fields.map((field) => field.id)).not.toContain('customerId');
    expect(fields.map((field) => field.id)).toEqual(
      expect.arrayContaining(['supplyDate', 'paymentTermsDays', 'discountAmount', 'notesPrinted']),
    );
  });

  it('is told apart from another form by what it is of, which a picker would have made impossible', () => {
    // The descriptor is stringified to key the form; a search function in it could not be.
    expect(() => JSON.stringify(invoiceForm(options, invoice()))).not.toThrow();
  });

  it('starts a new invoice at the default establishment and the customer’s terms', () => {
    const values = invoiceValues(null, options);
    expect(values['establishmentId']).toBe('e1');
    expect(values['paymentTermsDays']).toBe('');
    expect(invoiceValues(invoice({ paymentTermsDays: 0 }), options)['paymentTermsDays']).toBe('0');
  });

  it('asks what the operations are only where the law does, « from the lines » first, and sends the choice', () => {
    const ids = (form: ReturnType<typeof invoiceForm>): string[] =>
      form.sections.flatMap((section) => section.fields.map((field) => field.id));
    expect(ids(invoiceForm(options, invoice()))).not.toContain('operationCategory');

    const french = { ...options, operationCategory: true };
    const field = invoiceForm(french, invoice())
      .sections.flatMap((section) => section.fields)
      .find((each) => each.id === 'operationCategory');
    expect(field?.kind).toBe('select');
    expect(field?.options?.map((option) => option.value)).toEqual([
      '',
      'goods',
      'services',
      'both',
    ]);
    expect(field?.hint).toBe('invoices.form.operations_hint');
    const credit = invoiceForm(french, invoice({ type: 'credit_note' }))
      .sections.flatMap((section) => section.fields)
      .find((each) => each.id === 'operationCategory');
    expect([credit?.readOnly, credit?.hint]).toEqual([
      true,
      'invoices.form.operations_credit_note_hint',
    ]);

    expect(invoiceValues(null, french)['operationCategory']).toBe('');
    const chosen = invoiceValues(invoice({ operationCategory: 'services' }), french);
    expect(chosen['operationCategory']).toBe('services');
    const lines = new FormArray<LineGroup>([]);
    expect(invoiceInput(chosen, lines, [], 'k1').operationCategory).toBe('services');
    expect(invoiceInput(invoiceValues(null, french), lines, [], 'k1').operationCategory).toBeNull();
    expect(
      invoiceInput(invoiceValues(null, options), lines, [], 'k1').operationCategory,
    ).toBeNull();
  });

  it('prefills the document taxes: the company’s defaults and the customer’s, less what its regime refuses', () => {
    expect(defaultDocumentTaxes(options, carthage)).toEqual(['s1', 'w1']);
    expect(defaultDocumentTaxes(options, embassy)).toEqual([]);
    expect(documentTaxOptions(options, embassy, ['s1']).map((each) => each.id)).toEqual([
      'w1',
      's1',
    ]);
  });

  describe('lines', () => {
    it('discounts a new line by the customer’s default rate', () => {
      expect(lineGroup(null, options, carthage).controls.discountRate.value).toBe('5');
      expect(lineGroup(null, options, null).controls.discountRate.value).toBe('');
    });

    it('starts a new line with the company’s default VAT, as the API charges a free line, less what the regime refuses', () => {
      const withDefaultVat: InvoiceOptions = {
        ...options,
        taxes: options.taxes.map((each) =>
          each.id === 't1' ? { ...each, isDefault: true } : each,
        ),
      };
      expect(lineGroup(null, withDefaultVat, null).controls.taxComponentIds.value).toEqual(['t1']);
      expect(lineGroup(null, withDefaultVat, carthage).controls.taxComponentIds.value).toEqual([
        't1',
      ]);
      expect(lineGroup(null, withDefaultVat, embassy).controls.taxComponentIds.value).toEqual([]);
      expect(lineGroup(null, options, null).controls.taxComponentIds.value).toEqual([]);
    });

    it('takes off a line the taxes a newly chosen customer’s regime refuses, and adds none', () => {
      const line = lineGroup(null, options, null);
      line.controls.taxComponentIds.setValue(['t1', 'f1']);

      dropRefusedLineTaxes(line, options, ['vat']);
      expect(line.controls.taxComponentIds.value).toEqual(['f1']);

      dropRefusedLineTaxes(line, options, []);
      expect(line.controls.taxComponentIds.value).toEqual(['f1']);
    });

    it('fills a line from its product, less the taxes the customer’s regime refuses', () => {
      const line = lineGroup(null, options, null);
      applyProduct(line, design, options, ['vat']);
      expect(line.getRawValue()).toMatchObject({
        productId: 'p1',
        productReference: 'ART-1',
        productName: 'Conception',
        description: 'Conception',
        unitId: 'u2',
        unitPriceNet: '1800.000',
        taxComponentIds: ['f1'],
      });
      // The line carries the product's own words, which is what lets the picker show it without the catalogue.
      expect(pickedProduct(line)).toEqual({ id: 'p1', code: 'ART-1', name: 'Conception' });
    });

    it('clears what a line named when no product is picked, leaving what was typed on it', () => {
      const line = lineGroup(null, options, null);
      applyProduct(line, design, options, []);
      line.controls.description.setValue('Conception, revue');
      applyProduct(line, null, options, []);
      expect(line.getRawValue()).toMatchObject({
        productId: '',
        productReference: '',
        productName: '',
        description: 'Conception, revue',
      });
      expect(pickedProduct(line)).toBeNull();
    });

    it('refuses a quantity finer than the unit counts and a discount above 100', () => {
      const line = lineGroup(null, options, null);
      line.patchValue({ unitId: 'u1', quantity: '1.5', discountRate: '120' });
      expect(line.hasError('quantityDecimals')).toBe(true);
      expect(line.controls.discountRate.invalid).toBe(true);
      line.patchValue({ unitId: 'u2', discountRate: '12.5' });
      expect(line.hasError('quantityDecimals')).toBe(false);
      expect(line.controls.discountRate.valid).toBe(true);
    });

    it('discounts a line by a rate or by an amount, one at a time, never more than the line', () => {
      const lines = linesArray([], options, carthage);
      const line = lines.at(0);
      line.patchValue({ description: 'Vis', unitId: 'u1', quantity: '3', unitPriceNet: '50' });
      expect(line.controls.discountRate.value).toBe('5');

      // Switching to an amount drops the rate, so the API is never sent both.
      line.controls.discountKind.setValue('amount');
      expect(line.controls.discountRate.value).toBe('');
      line.patchValue({ discountAmount: ' 20 ' });
      expect(figuresReady(line)).toBe(true);
      expect(invoiceInput(invoiceValues(null, options), lines, [], 'k1').lines[0]).toMatchObject({
        discountRate: null,
        discountAmount: '20',
      });

      // The line comes to 3 × 50: the whole of it may be taken off, not a thousandth more.
      line.patchValue({ discountAmount: '150.001' });
      expect(line.hasError('discountAboveLine')).toBe(true);
      expect(figuresReady(line)).toBe(false);
      line.patchValue({ discountAmount: '150' });
      expect(line.hasError('discountAboveLine')).toBe(false);
      // No finer than the currency counts, nor below zero.
      line.patchValue({ discountAmount: '1.2345' });
      expect(line.controls.discountAmount.invalid).toBe(true);
      line.patchValue({ discountAmount: '-1' });
      expect(line.controls.discountAmount.invalid).toBe(true);

      line.controls.discountKind.setValue('rate');
      expect(line.controls.discountAmount.value).toBe('');
      line.patchValue({ discountRate: '10' });
      expect(invoiceInput(invoiceValues(null, options), lines, [], 'k1').lines[0]).toMatchObject({
        discountRate: '10',
        discountAmount: null,
      });
    });

    it('compares a discount with its line as the API rounds the line, at the currency’s scale', () => {
      const line = lineGroup(null, options, null);
      // 3 × 0.3335 is 1.0005, which the line comes to as 1.001.
      line.patchValue({ unitId: 'u1', quantity: '3', unitPriceNet: '0.3335' });
      line.controls.discountKind.setValue('amount');
      line.patchValue({ discountAmount: '1.001' });
      expect(line.hasError('discountAboveLine')).toBe(false);
      line.patchValue({ discountAmount: '1.002' });
      expect(line.hasError('discountAboveLine')).toBe(true);
    });

    it('reads a line discounted by an amount back as one, at the currency’s scale', () => {
      const lines = linesArray(
        [
          {
            productId: null,
            description: 'Palette',
            quantity: '2.000',
            unitId: 'u1',
            unitPriceNet: '35.0000',
            discountRate: null,
            discountAmount: '7.500',
            taxComponentIds: [],
            sourceDeliveryNoteLineId: null,
            sourceLeft: null,
            productReference: null,
            productName: null,
            productTracking: null,
            lotCode: null,
            returned: false,
            deductsInvoiceId: null,
            net: '62.500',
          },
        ],
        { ...options, currencyScale: 2 },
        null,
      );
      expect(lines.at(0).controls.discountKind.value).toBe('amount');
      expect(lines.at(0).controls.discountAmount.value).toBe('7.50');
      expect(lines.at(0).valid).toBe(true);
    });

    it('says what is on hand of a line’s product and what the document leaves of it, in exact decimals', () => {
      const lines = linesArray([], options, null);
      const fill = (at: number, values: Record<string, string>) =>
        lines.at(at).patchValue({ unitId: 'u1', description: 'x', unitPriceNet: '1', ...values });
      lines.push(lineGroup(null, options, null));
      lines.push(lineGroup(null, options, null));
      lines.push(lineGroup(null, options, null));
      lines.push(lineGroup(null, options, null));
      fill(0, { productId: 'p1', quantity: '3' });
      fill(1, { productId: 'p1', quantity: '0.5', unitId: 'u2' });
      fill(2, { productId: 'p1', quantity: '2', sourceDeliveryNoteLineId: 'dl1' });
      fill(3, { productId: 'p1', quantity: '4' });
      fill(4, { productId: 'p2', quantity: '1' });
      const onHand = new Map([['p1', { unitId: 'u1', onHand: '6.500' }]]);

      expect(lineStock(lines, onHand)).toEqual([
        // Three and four of the line's own unit leave half a piece short; the delivery note already took its two.
        { onHand: '6.500', unitId: 'u1', left: '-0.500' },
        // Counted in another unit than the stock, it moves none, so it is told only what is there.
        { onHand: '6.500', unitId: 'u1', left: null },
        { onHand: '6.500', unitId: 'u1', left: null },
        { onHand: '6.500', unitId: 'u1', left: '-0.500' },
        // No stock is kept of it, or it is not the company's to say.
        null,
      ]);

      lines.at(3).patchValue({ quantity: 'trois' });
      expect(lineStock(lines, onHand)[0]?.left).toBe('3.500');
      lines.at(0).patchValue({ productId: '' });
      expect(lineStock(lines, onHand)[0]).toBeNull();
    });

    it('names the lot or serial sold only on a line of a product tracked by one', () => {
      // docs/SPEC.md § 7, 2026-09-24 12:40 row 5.
      const lines = linesArray([], options, null);
      const line = lines.at(0);
      applyProduct(line, { ...design, tracking: 'serial' }, options, []);
      line.patchValue({ lotCode: ' SN-7 ' });
      expect(line.valid).toBe(true);
      expect(invoiceInput(invoiceValues(null, options), lines, [], 'k1').lines[0].lotCode).toBe(
        'SN-7',
      );

      line.patchValue({ lotCode: 'SN 7' });
      expect(line.controls.lotCode.hasError('pattern')).toBe(true);

      // Another product, not tracked: the serial typed for the first one is not sent with it.
      line.patchValue({ lotCode: 'SN-7' });
      applyProduct(line, { ...design, id: 'p2' }, options, []);
      expect(line.controls.lotCode.value).toBe('');
      expect(
        invoiceInput(invoiceValues(null, options), lines, [], 'k1').lines[0].lotCode,
      ).toBeNull();
    });

    it("says a credit note's goods came back only for a line that names a product", () => {
      const lines = linesArray([], options, null);
      const line = lines.at(0);
      line.patchValue({ returned: true });
      expect(invoiceInput(invoiceValues(null, options), lines, [], 'k1').lines[0].returned).toBe(
        false,
      );

      applyProduct(line, design, options, []);
      line.patchValue({ returned: true });
      expect(invoiceInput(invoiceValues(null, options), lines, [], 'k1').lines[0].returned).toBe(
        true,
      );

      // The product taken off again: what was ticked for it goes with it.
      applyProduct(line, null, options, []);
      expect(line.controls.returned.value).toBe(false);
    });

    it('keeps where a line came from through the form', () => {
      const lines = linesArray(
        [
          {
            productId: null,
            description: 'Palette',
            quantity: '2.000',
            unitId: 'u1',
            unitPriceNet: '35.0000',
            discountRate: null,
            discountAmount: null,
            taxComponentIds: [],
            sourceDeliveryNoteLineId: 'dl1',
            sourceLeft: null,
            productReference: null,
            productName: null,
            productTracking: null,
            lotCode: null,
            returned: false,
            deductsInvoiceId: null,
            net: '70.000',
          },
        ],
        options,
        null,
      );
      const input = invoiceInput(invoiceValues(null, options), lines, ['s1'], 'k1');
      expect(input.lines[0]).toMatchObject({
        quantity: '2',
        discountRate: null,
        sourceDeliveryNoteLineId: 'dl1',
      });
    });
  });

  describe('a line taken from a delivery note', () => {
    const sourced = (sourceLeft: string | null) =>
      linesArray(
        [
          {
            productId: 'p1',
            description: 'Palette',
            quantity: '6.000',
            unitId: 'u1',
            unitPriceNet: '35.0000',
            discountRate: null,
            discountAmount: null,
            taxComponentIds: [],
            sourceDeliveryNoteLineId: 'dl1',
            sourceLeft,
            productReference: 'PAL',
            productName: 'Palette',
            productTracking: null,
            lotCode: null,
            returned: false,
            deductsInvoiceId: null,
            net: '210.000',
          },
        ],
        options,
        null,
      ).at(0);

    it('takes no more than the note leaves it, compared in exact decimals', () => {
      const line = sourced('6.000');
      expect(sourceLeftOf(line)).toBe('6');
      expect(line.hasError('aboveSource')).toBe(false);
      line.controls.quantity.setValue('6.001');
      expect(line.hasError('aboveSource')).toBe(true);
      line.controls.quantity.setValue('5.999');
      expect(line.hasError('aboveSource')).toBe(false);
      line.controls.quantity.setValue('10');
      expect(line.hasError('aboveSource')).toBe(true);
    });

    it('is not capped where the API names no room, and the room is never sent back', () => {
      const line = sourced(null);
      expect(sourceLeftOf(line)).toBeNull();
      line.controls.quantity.setValue('1000');
      expect(line.hasError('aboveSource')).toBe(false);
      const input = invoiceInput(
        invoiceValues(null, options),
        new FormArray([sourced('6.000')]),
        [],
        'k1',
      );
      expect(input.lines[0]).not.toHaveProperty('sourceLeft');
    });
  });

  it('sends the header as the API takes it: trimmed, empty as no value, the terms as a number', () => {
    const values = {
      ...invoiceValues(null, options),
      paymentTermsDays: ' 45 ',
      discountAmount: ' ',
      customerReference: '  PO-9 ',
    };
    const input = invoiceInput(values, linesArray([], options, null), ['s1', 'w1'], 'k1');
    expect(input).toMatchObject({
      customerId: 'k1',
      establishmentId: 'e1',
      paymentTermsDays: 45,
      discountAmount: null,
      customerReference: 'PO-9',
      documentTaxComponentIds: ['s1', 'w1'],
    });
    expect(
      invoiceInput({ ...values, paymentTermsDays: '' }, linesArray([], options, null), [], 'k1')
        .paymentTermsDays,
    ).toBeNull();
  });

  // docs/SPEC.md § 7, 2026-09-19 21:55: amounts show and take the locale's decimal separator.
  it('asks the document discount and a payment’s amount as decimals', () => {
    const decimals = (form: ReturnType<typeof paymentForm>) =>
      form.sections
        .flatMap((section) => section.fields)
        .filter((field) => field.kind === 'decimal')
        .map((field) => field.id);
    expect(decimals(invoiceForm(options, null))).toEqual(['discountAmount']);
    expect(decimals(paymentForm())).toEqual(['amount']);
  });

  it('records a payment on today with the amount still due, and sends it trimmed', () => {
    const form = paymentForm();
    expect(form.sections.flatMap((s) => s.fields).map((f) => f.id)).toEqual([
      'date',
      'amount',
      'method',
      'reference',
      'notes',
    ]);
    expect(
      paymentInput({
        date: '2026-09-16',
        amount: ' 5950.000 ',
        method: 'transfer',
        reference: '',
        notes: ' ok ',
      }),
    ).toEqual({
      date: '2026-09-16',
      amount: '5950.000',
      method: 'transfer',
      reference: null,
      notes: 'ok',
    });
  });
});
