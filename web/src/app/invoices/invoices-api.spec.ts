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

  it('reads the form options, taxes of every kind included', async () => {
    const pending = api.options('c 1');
    http.expectOne('/api/companies/c%201/invoice-options').flush({
      currency: 'TND',
      currencyScale: 3,
      establishments: [{ id: 'e1', code: 'SIEGE', name: 'Siège', isDefault: true }],
      customers: [
        {
          id: 'k1',
          number: 'CLI-1',
          name: 'Carthage',
          excludedFamilies: [],
          defaultDiscountRate: '5',
          defaultTaxComponentIds: ['w1'],
        },
      ],
      products: [],
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
    expect(options.customers[0]?.defaultDiscountRate).toBe('5');
    expect(options.customers[0]?.defaultTaxComponentIds).toEqual(['w1']);
    expect(options.taxes[0]).toMatchObject({ kind: 'fixed_document', amount: '1.000' });
  });

  it('reads an issued invoice with its snapshot name, figures and payments', async () => {
    const pending = api.invoice('c1', 'i1');
    http.expectOne('/api/companies/c1/invoices/i1').flush(issued);

    const invoice = await pending;
    expect(invoice.customerName).toBe('Groupe Carthage Médias');
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

  it('lists the invoices and credit notes', async () => {
    const pending = api.invoices('c1');
    http.expectOne('/api/companies/c1/invoices').flush([issued]);
    expect((await pending).map((row) => row.number)).toEqual(['FAC-2026-00045']);
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
      [() => api.creditNote('c1', 'i1'), '/api/companies/c1/invoices/i1/credit-notes'],
    ] as const) {
      const pending = call();
      const request = http.expectOne(path);
      expect(request.request.method).toBe('POST');
      request.flush(issued);
      await pending;
    }
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
