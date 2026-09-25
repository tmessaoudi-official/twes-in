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
  linesArray,
  paymentForm,
  paymentInput,
  shownStatus,
} from './invoice-forms';
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
    documentTaxComponentIds: ['s1'],
    lines: [],
    subtotalNet: '1200.000',
    documentDiscount: '0.000',
    totalNet: '1200.000',
    taxes: [],
    totalTax: '228.000',
    fixedTaxes: [],
    total: '1428.000',
    withholdings: [],
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
      expect(invoiceSearch({ ...query, filters: { status: 'overdue' } }).status).toBe('overdue');
      expect(invoiceSearch({ ...query, filters: { status: 'paid' } }).status).toBe('paid');
      expect(invoiceSearch({ ...query, filters: { status: 'nonsense' } }).status).toBeNull();
    });

    it('passes the kind of document and the sort the column names', () => {
      expect(invoiceSearch({ ...query, filters: { type: 'credit_note' } }).documentType).toBe(
        'credit_note',
      );
      expect(invoiceSearch({ ...query, filters: { type: 'nonsense' } }).documentType).toBeNull();
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
            taxComponentIds: [],
            sourceDeliveryNoteLineId: 'dl1',
            productReference: null,
            productName: null,
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
