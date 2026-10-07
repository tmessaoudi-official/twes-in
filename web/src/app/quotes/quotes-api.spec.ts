// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { InvoicesApi } from '../invoices/invoices-api';
import { QuotesApi, QuotesRefused } from './quotes-api';
import type { QuoteInput } from './quotes-types';

const input: QuoteInput = {
  customerId: 'k1',
  establishmentId: null,
  customerReference: 'RFQ-12',
  notesPrinted: null,
  notesInternal: null,
  discountAmount: '10',
  lines: [
    {
      productId: null,
      description: 'Pose',
      quantity: '1.5',
      unitId: 'u1',
      unitPriceNet: '40',
      discountRate: null,
      taxComponentIds: ['t1'],
    },
  ],
};

const SEARCH = {
  page: 1,
  itemsPerPage: 25,
  q: '',
  status: [],
  customerIds: [],
  intervals: {},
  order: null,
} as const;

/** A quote as the API answers it, with what a case changes. */
function raw(changes: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    id: 'q1',
    number: null,
    status: 'draft',
    expired: false,
    customerId: 'k1',
    customerName: 'Acme',
    customerSnapshot: null,
    lines: [
      {
        productId: 'p1',
        productReference: 'ART-001',
        productName: 'Tour CNC',
        description: 'Tour CNC',
        quantity: '2.000',
        unitId: 'u1',
        unitPriceNet: '1250.0000',
        discountRate: '10.000',
        taxComponentIds: ['t1', 't2'],
        net: '2250.000',
      },
    ],
    subtotalNet: '2250.000',
    documentDiscount: '0.000',
    taxes: [{ code: 'TVA19', rate: '19.000', base: '2250.000', amount: '427.500' }],
    totalTax: '427.500',
    total: '2677.500',
    attachmentCount: 0,
    ...changes,
  };
}

describe('QuotesApi', () => {
  let api: QuotesApi;
  let http: HttpTestingController;
  const invoices = { productPrice: vi.fn() };

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: InvoicesApi, useValue: invoices },
      ],
    });
    invoices.productPrice.mockReset();
    api = TestBed.inject(QuotesApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('reads the options under the quote, not the invoice', async () => {
    const pending = api.options('c 1');
    http.expectOne('/api/companies/c%201/quote-options').flush({
      currency: 'TND',
      currencyScale: 3,
      establishments: [{ id: 'e1', code: '000', name: 'Siège', isDefault: true }],
      units: [{ id: 'u1', code: 'C62', name: 'Unité', decimals: 0 }],
      taxes: [],
    });
    expect((await pending).currencyScale).toBe(3);
  });

  it('picks products from the quote options, by words or by ids', async () => {
    const byWords = api.pickProducts('c1', { words: 'tour' });
    http
      .expectOne(
        (request) =>
          request.url === '/api/companies/c1/quote-options/products' &&
          request.params.get('q') === 'tour',
      )
      .flush([]);
    expect(await byWords).toEqual([]);
    const byIds = api.pickCustomers('c1', { ids: ['k1'] });
    http
      .expectOne(
        (request) =>
          request.url === '/api/companies/c1/quote-options/customers' &&
          request.params.getAll('ids[]')?.join() === 'k1',
      )
      .flush([]);
    expect(await byIds).toEqual([]);
  });

  it('reads a quote into the lines the invoice editor reads: no delivery note, no lot, no deposit', async () => {
    const pending = api.quote('c1', 'q1');
    http.expectOne('/api/companies/c1/quotes/q1').flush(raw());
    const quote = await pending;
    expect(quote.lines[0]).toEqual({
      productId: 'p1',
      description: 'Tour CNC',
      quantity: '2.000',
      unitId: 'u1',
      unitPriceNet: '1250.0000',
      discountRate: '10.000',
      taxComponentIds: ['t1', 't2'],
      sourceDeliveryNoteLineId: null,
      sourceLeft: null,
      productReference: 'ART-001',
      productName: 'Tour CNC',
      productTracking: null,
      lotCode: null,
      returned: false,
      deductsInvoiceId: null,
      net: '2250.000',
    });
  });

  it('pages the list as JSON-LD and narrows it as asked', async () => {
    const pending = api.quotes('c1', {
      ...SEARCH,
      status: ['sent'],
      customerIds: ['k1'],
      intervals: { 'issueDate.from': '2026-10-01' },
      order: { key: 'validUntil', direction: 'asc' },
    });
    const request = http.expectOne((each) => each.url === '/api/companies/c1/quotes');
    expect(request.request.headers.get('Accept')).toBe('application/ld+json');
    expect(request.request.params.getAll('status[]')).toEqual(['sent']);
    expect(request.request.params.getAll('customerId[]')).toEqual(['k1']);
    expect(request.request.params.get('issueDate[from]')).toBe('2026-10-01');
    expect(request.request.params.get('order[validUntil]')).toBe('asc');
    request.flush({ member: [raw({ status: 'sent', expired: true })], totalItems: 1 });
    const page = await pending;
    expect(page.total).toBe(1);
    expect(page.rows[0]?.expired).toBe(true);
  });

  it('creates a draft with the lines as typed', async () => {
    const pending = api.create('c1', input);
    const request = http.expectOne('/api/companies/c1/quotes');
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual(input);
    request.flush(raw());
    expect((await pending).id).toBe('q1');
  });

  it('sends, cancels and invoices with no body', async () => {
    for (const [call, path] of [
      [() => api.send('c1', 'q1'), 'send'],
      [() => api.cancel('c1', 'q1'), 'cancel'],
      [() => api.invoice('c1', 'q1'), 'invoice'],
    ] as const) {
      const pending = call();
      const request = http.expectOne(`/api/companies/c1/quotes/q1/${path}`);
      expect(request.request.body).toBeNull();
      request.flush(raw());
      await pending;
    }
  });

  it('draws a deposit as a percentage or an amount, and reads the deposits the quote lists', async () => {
    const byShare = api.deposit('c1', 'q1', { percentage: '30' });
    const share = http.expectOne('/api/companies/c1/quotes/q1/deposit-invoices');
    expect(share.request.method).toBe('POST');
    expect(share.request.body).toEqual({ depositPercentage: '30', depositAmount: null });
    share.flush(
      raw({
        status: 'accepted',
        deposits: [{ invoiceId: 'i1', number: null, status: 'draft', total: '803.250' }],
      }),
    );
    expect((await byShare).deposits).toEqual([
      { invoiceId: 'i1', number: null, status: 'draft', total: '803.250' },
    ]);

    const byAmount = api.deposit('c1', 'q1', { amount: '500.000' });
    const amount = http.expectOne('/api/companies/c1/quotes/q1/deposit-invoices');
    expect(amount.request.body).toEqual({ depositPercentage: null, depositAmount: '500.000' });
    amount.flush(raw());
    expect((await byAmount).deposits).toEqual([]);
  });

  it('names a refused deposit, and invoicing past a draft deposit, for what they are', async () => {
    const deposit = api.deposit('c1', 'q1', { percentage: '99' });
    http
      .expectOne('/api/companies/c1/quotes/q1/deposit-invoices')
      .flush({ detail: 'depositPercentage: beyond' }, { status: 422, statusText: 'Unprocessable' });
    await expect(deposit).rejects.toEqual(new QuotesRefused('deposit_refused'));

    const invoiced = api.invoice('c1', 'q1');
    http
      .expectOne('/api/companies/c1/quotes/q1/invoice')
      .flush({ detail: 'deposits: still a draft' }, { status: 422, statusText: 'Unprocessable' });
    await expect(invoiced).rejects.toEqual(new QuotesRefused('deposit_pending'));
  });

  it("sends an empty answer day as none, the company's today", async () => {
    const accepted = api.accept('c1', 'q1', { answeredOn: ' ' });
    const accept = http.expectOne('/api/companies/c1/quotes/q1/accept');
    expect(accept.request.body).toEqual({ answeredOn: null });
    accept.flush(raw({ status: 'accepted' }));
    await accepted;

    const refused = api.refuse('c1', 'q1', { answeredOn: '2026-10-05', refusalReason: '  ' });
    const refuse = http.expectOne('/api/companies/c1/quotes/q1/refuse');
    expect(refuse.request.body).toEqual({ answeredOn: '2026-10-05', refusalReason: null });
    refuse.flush(raw({ status: 'refused' }));
    await refused;
  });

  it('attaches a file as one multipart part named file', async () => {
    const file = new File(['%PDF-1.4'], 'signé.pdf', { type: 'application/pdf' });
    const pending = api.attach('c1', 'q1', file);
    const request = http.expectOne('/api/companies/c1/quotes/q1/attachments');
    expect((request.request.body as FormData).get('file')).toBeInstanceOf(File);
    request.flush({ id: 'a1', name: 'signé.pdf', mime: 'application/pdf', size: 8, createdAt: '' });
    expect((await pending).name).toBe('signé.pdf');
  });

  it('names a refused file as such, and a refused field as invalid', async () => {
    const file = api.attach('c1', 'q1', new File(['x'], 'x.exe'));
    http
      .expectOne('/api/companies/c1/quotes/q1/attachments')
      .flush({ detail: 'file: refused' }, { status: 422, statusText: 'Unprocessable' });
    await expect(file).rejects.toEqual(new QuotesRefused('file_refused'));

    const revised = api.revise('c1', 'q1', input);
    http
      .expectOne('/api/companies/c1/quotes/q1')
      .flush({ detail: 'lines: none' }, { status: 422, statusText: 'Unprocessable' });
    await expect(revised).rejects.toEqual(new QuotesRefused('invalid'));

    const customer = api.create('c1', input);
    http
      .expectOne('/api/companies/c1/quotes')
      .flush({ detail: 'customerId: inactive' }, { status: 422, statusText: 'Unprocessable' });
    await expect(customer).rejects.toEqual(new QuotesRefused('customer_unavailable'));
  });

  it("asks a line's price where an invoice line asks it", async () => {
    invoices.productPrice.mockResolvedValue({ unitPriceNet: '9', priceListName: 'Gros' });
    expect(await api.productPrice('c1', 'p1', 'k1', '2')).toEqual({
      unitPriceNet: '9',
      priceListName: 'Gros',
    });
    expect(invoices.productPrice).toHaveBeenCalledWith('c1', 'p1', 'k1', '2');
  });
});
