// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { InvoicesApi, InvoicesRefused } from './invoices-api';
import type { InvoiceInput } from './invoices-types';

const input: InvoiceInput = {
  customerId: 'k1',
  establishmentId: 'e1',
  supplyDate: '2026-09-15',
  paymentTermsDays: 30,
  customerReference: 'PO-77',
  notesPrinted: 'Virement',
  notesInternal: null,
  discountAmount: null,
  documentTaxComponentIds: null,
  lines: [
    {
      productId: 'p1',
      description: 'Conception',
      quantity: '1',
      unitId: 'u1',
      unitPriceNet: '1800',
      discountRate: '10',
      taxComponentIds: ['t1'],
      sourceDeliveryNoteLineId: null,
    },
  ],
};

const issued = {
  id: 'i1',
  type: 'invoice',
  correctsInvoiceId: null,
  number: 'FAC-2026-00045',
  status: 'partially_paid',
  customerId: 'k1',
  establishmentId: 'e1',
  issueDate: '2026-09-10',
  dueDate: '2026-10-10',
  supplyDate: null,
  paymentTermsDays: 30,
  customerReference: null,
  notesPrinted: null,
  notesInternal: null,
  discountAmount: null,
  documentTaxComponentIds: ['s1'],
  lines: [
    {
      productId: null,
      description: 'Campagne',
      quantity: '1.000',
      unitId: 'u1',
      unitPriceNet: '10000.0000',
      discountRate: null,
      taxComponentIds: ['t1'],
      sourceDeliveryNoteLineId: 'dl1',
      net: '10000.000',
    },
  ],
  subtotalNet: '10000.000',
  documentDiscount: '0.000',
  totalNet: '10000.000',
  taxes: [{ code: 'TVA', rate: '19.000', base: '10000.000', amount: '1900.000' }],
  totalTax: '1900.000',
  fixedTaxes: [{ code: 'TIMBRE', amount: '1.000' }],
  total: '11901.000',
  withholdings: [],
  amountDue: '5951.000',
  amountPaid: '5950.000',
  amountCredited: '0.000',
  payments: [
    {
      id: 'y1',
      date: '2026-09-12',
      amount: '5950.000',
      method: 'transfer',
      reference: 'VIR 882104',
      notes: null,
      recordedBy: 'u9',
      createdAt: '2026-09-12T13:02:00+00:00',
    },
  ],
  customerSnapshot: { name: 'Groupe Carthage Médias' },
};

describe('InvoicesApi', () => {
  let api: InvoicesApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(InvoicesApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('reads the home summary as the API works it out, the unknown aging bucket dropped', async () => {
    const pending = api.summary('c 1');
    http.expectOne('/api/companies/c%201/invoice-summary').flush({
      currency: 'TND',
      currencyScale: 3,
      today: '2026-09-21',
      outstanding: '3531.050',
      notYetDue: '1500.000',
      overdue: '2031.050',
      overdueCount: 3,
      oldestOverdueDays: 82,
      aging: [
        { bucket: 'not_due', amount: '1500.000', count: 2 },
        { bucket: 'days_over_45', amount: '881.000', count: 1 },
        { bucket: 'someday', amount: '1.000', count: 1 },
      ],
      toChase: [
        {
          invoiceId: 'i3',
          number: 'FAC-O3',
          customerName: 'Transports Sahel',
          dueDate: '2026-07-01',
          amountDue: '881.000',
          daysLate: 82,
        },
      ],
      toChaseCount: 4,
      toChaseAmount: '2331.050',
      collected: [{ month: '2026-09', amount: '800.000' }],
      vat: [{ code: 'TVA19', rate: '19.000', amount: '171.000' }],
      vatTotal: '171.000',
    });
    const summary = await pending;
    expect(summary.aging.map((each) => each.bucket)).toEqual(['not_due', 'days_over_45']);
    expect(summary).toMatchObject({
      today: '2026-09-21',
      oldestOverdueDays: 82,
      toChase: [{ invoiceId: 'i3', daysLate: 82 }],
      toChaseCount: 4,
      collected: [{ month: '2026-09', amount: '800.000' }],
      vatTotal: '171.000',
    });
  });

  it('reads the form options, taxes of every kind included', async () => {
    const pending = api.options('c 1');
    http.expectOne('/api/companies/c%201/invoice-options').flush({
      currency: 'TND',
      currencyScale: 3,
      establishments: [{ id: 'e1', code: 'SIEGE', name: 'Siège', isDefault: true }],
      units: [{ id: 'u1', code: 'C62', name: 'Unité', decimals: 0 }],
      taxes: [
        {
          id: 's1',
          code: 'TIMBRE',
          name: 'Timbre fiscal',
          kind: 'fixed_document',
          family: 'stamp',
          rate: null,
          amount: '1.000',
          threshold: null,
          entersVatBase: false,
          isDefault: true,
        },
      ],
    });

    const options = await pending;
    expect(options.currencyScale).toBe(3);
    expect(options.establishments[0]?.code).toBe('SIEGE');
    expect(options.taxes[0]).toMatchObject({ kind: 'fixed_document', amount: '1.000' });
  });

  it('asks a picker for the few a person means, and by id for the ones a document already names', async () => {
    const searched = api.pickCustomers('c1', { words: '  carth ' });
    const search = http.expectOne(
      (request) => request.url === '/api/companies/c1/invoice-options/customers',
    );
    expect(search.request.params.get('q')).toBe('carth');
    expect(search.request.params.has('ids[]')).toBe(false);
    search.flush([
      {
        id: 'k1',
        number: 'CLI-1',
        name: 'Carthage',
        excludedFamilies: ['stamp'],
        defaultDiscountRate: '5',
        defaultTaxComponentIds: ['w1'],
      },
    ]);
    expect((await searched)[0]).toMatchObject({ number: 'CLI-1', defaultDiscountRate: '5' });

    const named = api.pickProducts('c1', { ids: ['p1', 'p2'] });
    const resolve = http.expectOne(
      (request) => request.url === '/api/companies/c1/invoice-options/products',
    );
    // Asking for what a document names is not a search: the words are left out entirely.
    expect(resolve.request.params.getAll('ids[]')).toEqual(['p1', 'p2']);
    expect(resolve.request.params.has('q')).toBe(false);
    resolve.flush([
      {
        id: 'p1',
        reference: 'ART-1',
        name: 'Conception',
        unitId: 'u2',
        unitPriceNet: '1800.0000',
        defaultTaxComponentIds: [],
      },
    ]);
    expect((await named)[0]?.reference).toBe('ART-1');
  });

  it('asks for nothing at all when a picker opens on no words, so the API answers its first few', async () => {
    const pending = api.pickCustomers('c1', { words: '   ' });
    const request = http.expectOne(
      (each) => each.url === '/api/companies/c1/invoice-options/customers',
    );
    expect(request.request.params.keys()).toEqual([]);
    request.flush([]);
    expect(await pending).toEqual([]);
  });

  it('reads an issued invoice with its snapshot name, figures and payments', async () => {
    const pending = api.invoice('c1', 'i1');
    http.expectOne('/api/companies/c1/invoices/i1').flush(issued);

    const invoice = await pending;
    expect(invoice.recordedCustomerName).toBe('Groupe Carthage Médias');
    expect(invoice.status).toBe('partially_paid');
    expect(invoice.amountDue).toBe('5951.000');
    expect(invoice.fixedTaxes).toEqual([{ code: 'TIMBRE', amount: '1.000' }]);
    expect(invoice.lines[0]?.sourceDeliveryNoteLineId).toBe('dl1');
    expect(invoice.payments[0]).toEqual({
      id: 'y1',
      date: '2026-09-12',
      amount: '5950.000',
      method: 'transfer',
      reference: 'VIR 882104',
      notes: null,
    });
  });

  it('asks the API for one page of documents, with what it searches, narrows and sorts by', async () => {
    const pending = api.invoices('c1', {
      page: 2,
      itemsPerPage: 25,
      q: '  carthage  ',
      status: 'overdue',
      documentType: 'invoice',
      customerId: 'cu1',
      order: { key: 'number', direction: 'desc' },
    });
    const request = http.expectOne(
      (candidate) => candidate.url === '/api/companies/c1/invoices' && candidate.method === 'GET',
    );
    expect(request.request.headers.get('Accept')).toBe('application/ld+json');
    expect(request.request.params.get('page')).toBe('2');
    expect(request.request.params.get('itemsPerPage')).toBe('25');
    // Trimmed, so a trailing space is not a different search.
    expect(request.request.params.get('q')).toBe('carthage');
    expect(request.request.params.get('status')).toBe('overdue');
    expect(request.request.params.get('documentType')).toBe('invoice');
    expect(request.request.params.get('customerId')).toBe('cu1');
    expect(request.request.params.get('order[number]')).toBe('desc');
    request.flush({ member: [issued], totalItems: 48 });

    const page = await pending;
    expect(page.rows.map((row) => row.number)).toEqual(['FAC-2026-00045']);
    expect(page.total).toBe(48);
  });

  it('refuses a page that came without its total, rather than showing one page as the whole list', async () => {
    const pending = api.invoices('c1', {
      page: 1,
      itemsPerPage: 25,
      q: '',
      status: null,
      documentType: null,
      customerId: null,
      order: null,
    });
    const request = http.expectOne(
      (candidate) => candidate.url === '/api/companies/c1/invoices' && candidate.method === 'GET',
    );
    expect(request.request.params.has('q')).toBe(false);
    expect(request.request.params.has('status')).toBe(false);
    request.flush({ member: [issued] });
    await expect(pending).rejects.toThrow();
  });

  it('drafts and revises with the input as the API takes it', async () => {
    const created = api.create('c1', input);
    const post = http.expectOne('/api/companies/c1/invoices');
    expect(post.request.method).toBe('POST');
    expect(post.request.body).toEqual(input);
    post.flush({ ...issued, status: 'draft', number: null });
    expect((await created).number).toBeNull();

    const revised = api.revise('c1', 'i1', input);
    const put = http.expectOne('/api/companies/c1/invoices/i1');
    expect(put.request.method).toBe('PUT');
    expect(put.request.body).not.toHaveProperty('id');
    put.flush(issued);
    await revised;
  });

  it('issues, cancels and drafts a credit note through their own endpoints', async () => {
    for (const [call, path] of [
      [() => api.issue('c1', 'i1'), '/api/companies/c1/invoices/i1/issue'],
      [() => api.cancel('c1', 'i1'), '/api/companies/c1/invoices/i1/cancel'],
      [() => api.creditNote('c1', 'i1', 'Retour'), '/api/companies/c1/invoices/i1/credit-notes'],
    ] as const) {
      const pending = call();
      const request = http.expectOne(path);
      expect(request.request.method).toBe('POST');
      request.flush(issued);
      await pending;
    }
  });

  it('drafts a credit note with its reason, and reads the reason back', async () => {
    const drafted = api.creditNote('c1', 'i1', 'Retour de marchandise');
    const request = http.expectOne('/api/companies/c1/invoices/i1/credit-notes');
    expect(request.request.body).toEqual({ creditNoteReason: 'Retour de marchandise' });
    request.flush({ ...issued, type: 'credit_note', creditNoteReason: 'Retour de marchandise' });
    expect((await drafted).creditNoteReason).toBe('Retour de marchandise');

    const read = api.invoice('c1', 'i1');
    http.expectOne('/api/companies/c1/invoices/i1').flush(issued);
    expect((await read).creditNoteReason).toBeNull();
  });

  it('records and deletes a payment', async () => {
    const recorded = api.recordPayment('c1', 'i1', {
      date: '2026-09-12',
      amount: '5950.000',
      method: 'transfer',
      reference: null,
      notes: null,
    });
    const post = http.expectOne('/api/companies/c1/invoices/i1/payments');
    expect(post.request.method).toBe('POST');
    expect(post.request.body).toMatchObject({ amount: '5950.000', method: 'transfer' });
    post.flush({});
    await recorded;

    const deleted = api.deletePayment('c1', 'i1', 'y 1');
    const request = http.expectOne('/api/companies/c1/invoices/i1/payments/y%201');
    expect(request.request.method).toBe('DELETE');
    request.flush(null, { status: 204, statusText: 'No Content' });
    await deleted;
  });

  it('turns a refusal into a code the screen translates', async () => {
    for (const [status, code] of [
      [404, 'not_found'],
      [409, 'conflict'],
      [422, 'invalid'],
    ] as const) {
      const pending = api.issue('c1', 'i1');
      http
        .expectOne('/api/companies/c1/invoices/i1/issue')
        .flush({}, { status, statusText: 'Refused' });
      await expect(pending).rejects.toEqual(new InvoicesRefused(code));
    }
  });

  it('names where a document is downloaded as a PDF', () => {
    expect(api.pdfUrl('c1', 'i 1')).toBe('/api/companies/c1/invoices/i%201/pdf');
  });
});
