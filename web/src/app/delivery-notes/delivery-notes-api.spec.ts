// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { InvoicesApi } from '../invoices/invoices-api';
import { DeliveryNotesApi, DeliveryNotesRefused } from './delivery-notes-api';
import type { DeliveryNoteInput } from './delivery-notes-types';

const input: DeliveryNoteInput = {
  customerId: 'k1',
  establishmentId: 'e1',
  deliveryDate: '2026-09-20',
  deliveryAddress: {
    line1: 'Quai 3',
    line2: null,
    postalCode: '1000',
    city: 'Tunis',
    countryCode: 'TN',
  },
  customerReference: 'PO-77',
  remarksPrinted: null,
  notesInternal: 'Fragile',
  lines: [
    {
      productId: 'p1',
      description: 'Portable 14"',
      quantity: '2',
      unitId: 'u1',
      unitPriceNet: '1250',
      taxComponentIds: ['t1'],
      lotCode: 'L-2409',
    },
  ],
};

/** A search that asks for nothing but the first page, so a case names only what it changes. */
const SEARCH = {
  page: 1,
  itemsPerPage: 25,
  q: '',
  status: [],
  customerIds: [],
  intervals: {},
  order: null,
} as const;

describe('DeliveryNotesApi', () => {
  let api: DeliveryNotesApi;
  let http: HttpTestingController;

  const invoices = { invoices: vi.fn() };

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: InvoicesApi, useValue: invoices },
      ],
    });
    invoices.invoices.mockReset();
    api = TestBed.inject(DeliveryNotesApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('reads the form options', async () => {
    const pending = api.options('c 1');
    http.expectOne('/api/companies/c%201/delivery-note-options').flush({
      currency: 'TND',
      currencyScale: 3,
      establishments: [{ id: 'e1', code: 'SIEGE', name: 'Siège', isDefault: true }],
      units: [{ id: 'u1', code: 'C62', name: 'Unité', decimals: 0 }],
      taxes: [
        {
          id: 't1',
          code: 'TVA19',
          name: 'TVA 19 %',
          family: 'vat',
          rate: '19',
          entersVatBase: false,
        },
      ],
    });

    const options = await pending;
    expect(options.currencyScale).toBe(3);
    expect(options.establishments[0]).toEqual({
      id: 'e1',
      code: 'SIEGE',
      name: 'Siège',
      isDefault: true,
    });
    expect(options.taxes[0]?.rate).toBe('19');
  });

  it('asks a picker for the few a person means, and by id for the ones a note already names', async () => {
    const searched = api.pickProducts('c1', { words: '  port ' });
    const search = http.expectOne(
      (request) => request.url === '/api/companies/c1/delivery-note-options/products',
    );
    expect(search.request.params.get('q')).toBe('port');
    search.flush([
      {
        id: 'p1',
        reference: 'ART-1',
        name: 'Portable',
        unitId: 'u1',
        unitPriceNet: '1250.0000',
        defaultTaxComponentIds: ['t1'],
      },
    ]);
    expect((await searched)[0]?.defaultTaxComponentIds).toEqual(['t1']);

    const named = api.pickCustomers('c1', { ids: ['k1'] });
    const resolve = http.expectOne(
      (request) => request.url === '/api/companies/c1/delivery-note-options/customers',
    );
    // Asking for what a note names is not a search: the words are left out entirely.
    expect(resolve.request.params.getAll('ids[]')).toEqual(['k1']);
    expect(resolve.request.params.has('q')).toBe(false);
    resolve.flush([{ id: 'k1', number: 'CLI-1', name: 'Carthage', excludedFamilies: ['vat'] }]);
    expect((await named)[0]?.excludedFamilies).toEqual(['vat']);
  });

  it('reads a note with nulls where the API sent none and the name it was validated with', async () => {
    const pending = api.note('c1', 'n 1');
    http.expectOne('/api/companies/c1/delivery-notes/n%201').flush({
      id: 'n 1',
      number: 'BL-2026-00001',
      status: 'validated',
      customerId: 'k1',
      customerName: 'Carthage',
      customerSnapshot: { number: 'CLI-1', name: 'Carthage Conseil' },
      issueDate: '2026-09-15',
      lines: [{ quantity: '2.000', unitId: 'u1', unitPriceNet: '1250.0000', net: '2500.000' }],
      subtotalNet: '2500.000',
      taxes: [{ code: 'TVA19', rate: '19', base: '2500.000', amount: '475.000' }],
      totalTax: '475.000',
      total: '2975.000',
    });

    expect(await pending).toEqual({
      id: 'n 1',
      number: 'BL-2026-00001',
      status: 'validated',
      customerId: 'k1',
      establishmentId: null,
      recordedCustomerName: 'Carthage Conseil',
      customerName: 'Carthage',
      issueDate: '2026-09-15',
      deliveryDate: null,
      deliveryAddress: {
        line1: null,
        line2: null,
        postalCode: null,
        city: null,
        countryCode: null,
      },
      customerReference: null,
      remarksPrinted: null,
      notesInternal: null,
      lines: [
        {
          productId: null,
          description: '',
          quantity: '2.000',
          unitId: 'u1',
          unitPriceNet: '1250.0000',
          taxComponentIds: [],
          productReference: null,
          productName: null,
          productTracking: null,
          lotCode: null,
          net: '2500.000',
        },
      ],
      subtotalNet: '2500.000',
      taxes: [{ code: 'TVA19', rate: '19', base: '2500.000', amount: '475.000' }],
      totalTax: '475.000',
      total: '2975.000',
    });
  });

  it('drafts and revises a note with the flat body the API takes', async () => {
    const created = api.create('c1', input);
    const post = http.expectOne('/api/companies/c1/delivery-notes');
    expect(post.request.method).toBe('POST');
    expect(post.request.body).toEqual({
      customerId: 'k1',
      establishmentId: 'e1',
      deliveryDate: '2026-09-20',
      deliveryAddressLine1: 'Quai 3',
      deliveryAddressLine2: null,
      deliveryPostalCode: '1000',
      deliveryCity: 'Tunis',
      deliveryCountryCode: 'TN',
      customerReference: 'PO-77',
      remarksPrinted: null,
      notesInternal: 'Fragile',
      lines: [
        {
          productId: 'p1',
          description: 'Portable 14"',
          quantity: '2',
          unitId: 'u1',
          unitPriceNet: '1250',
          taxComponentIds: ['t1'],
          lotCode: 'L-2409',
        },
      ],
    });
    post.flush({ id: 'n1', status: 'draft', customerId: 'k1' });
    expect((await created).id).toBe('n1');

    const revised = api.revise('c1', 'n1', input);
    const put = http.expectOne('/api/companies/c1/delivery-notes/n1');
    expect(put.request.method).toBe('PUT');
    put.flush({ id: 'n1', status: 'draft', customerId: 'k1' });
    await revised;

    const list = api.notes('c1', SEARCH);
    http
      .expectOne((candidate) => candidate.url === '/api/companies/c1/delivery-notes')
      .flush({ member: [{ id: 'n1', status: 'draft' }], totalItems: 1 });
    expect((await list).rows.map((row) => row.status)).toEqual(['draft']);
  });

  it('asks the API for one page of notes, with what it searches, narrows and sorts by', async () => {
    const pending = api.notes('c1', {
      ...SEARCH,
      page: 3,
      itemsPerPage: 50,
      q: '  BL-2026  ',
      status: ['validated', 'draft'],
      customerIds: ['k1', 'k2'],
      intervals: { 'issueDate.from': '2026-01-01', 'deliveryDate.to': '2026-12-31' },
      order: { key: 'deliveryDate', direction: 'asc' },
    });
    const request = http.expectOne(
      (candidate) =>
        candidate.url === '/api/companies/c1/delivery-notes' && candidate.method === 'GET',
    );
    expect(request.request.headers.get('Accept')).toBe('application/ld+json');
    expect(request.request.params.get('page')).toBe('3');
    expect(request.request.params.get('itemsPerPage')).toBe('50');
    // Trimmed, so a trailing space is not a different search.
    expect(request.request.params.get('q')).toBe('BL-2026');
    // Repeated parameters, one per value, which the API ORs; the ends of an interval keep API Platform's bracket form.
    expect(request.request.params.getAll('status[]')).toEqual(['validated', 'draft']);
    expect(request.request.params.getAll('customerId[]')).toEqual(['k1', 'k2']);
    expect(request.request.params.get('issueDate[from]')).toBe('2026-01-01');
    expect(request.request.params.get('deliveryDate[to]')).toBe('2026-12-31');
    expect(request.request.params.get('order[deliveryDate]')).toBe('asc');
    request.flush({ member: [{ id: 'n1', number: 'BL-2026-00001' }], totalItems: 91 });

    const page = await pending;
    expect(page.rows.map((row) => row.number)).toEqual(['BL-2026-00001']);
    expect(page.total).toBe(91);
  });

  it('names the file of what the list shows, with its words, choices and order and no page', () => {
    const search = {
      page: 3,
      itemsPerPage: 50,
      q: ' po ',
      status: ['validated' as const, 'draft' as const],
      customerIds: ['k1'],
      intervals: { 'issueDate.to': '2026-03-31' },
      order: { key: 'number' as const, direction: 'desc' as const },
    };

    expect(decodeURIComponent(api.exportUrl('c/1', search, 'xlsx'))).toBe(
      '/api/companies/c/1/exports/delivery-notes.xlsx?q=po&status[]=validated&status[]=draft&customerId[]=k1&issueDate[to]=2026-03-31&order[number]=desc',
    );
  });

  it('refuses a page that came without its total, rather than showing one page as the whole list', async () => {
    const pending = api.notes('c1', SEARCH);
    const request = http.expectOne(
      (candidate) =>
        candidate.url === '/api/companies/c1/delivery-notes' && candidate.method === 'GET',
    );
    expect(request.request.params.has('q')).toBe(false);
    expect(request.request.params.has('status')).toBe(false);
    request.flush({ member: [] });
    await expect(pending).rejects.toThrow();
  });

  // docs/SPEC.md § 7, 2026-09-26: « Bons de livraison »'s chips say how many each would list.
  it('asks how many notes each status would list, under the list’s own words and customer', async () => {
    const pending = api.statusCounts('c1', {
      ...SEARCH,
      page: 2,
      q: '  carthage  ',
      status: ['validated'],
      customerIds: ['k1'],
      intervals: { 'issueDate.from': '2026-01-01' },
      order: { key: 'number', direction: 'desc' },
    });
    const request = http.expectOne(
      (candidate) =>
        candidate.url === '/api/companies/c1/delivery-note-status-counts' &&
        candidate.method === 'GET',
    );
    // The chips narrow by status themselves, and a count has no page or order.
    expect(request.request.params.keys().sort()).toEqual(['customerId[]', 'issueDate[from]', 'q']);
    expect(request.request.params.get('q')).toBe('carthage');
    const statuses = { draft: 1, validated: 2, delivered: 0, cancelled: 1, invoiced: 0 };
    request.flush({ all: 4, statuses });
    expect(await pending).toEqual({ all: 4, statuses });
  });

  it('refuses counts that came without their figures, rather than showing none as nothing', async () => {
    const pending = api.statusCounts('c1', SEARCH);
    http.expectOne('/api/companies/c1/delivery-note-status-counts').flush({ all: 5 });
    await expect(pending).rejects.toThrow();
  });

  it('reads what delivering a note would do to its customer’s credit limit', async () => {
    const pending = api.credit('c1', 'n1');
    const request = http.expectOne('/api/companies/c1/delivery-notes/n1/credit');
    expect(request.request.method).toBe('GET');
    request.flush({
      deliveryNoteId: 'n1',
      limit: '1200.000',
      owed: '1000.000',
      noteTotal: '300.000',
      afterDelivery: '1300.000',
      over: true,
    });
    expect(await pending).toEqual({
      limit: '1200.000',
      owed: '1000.000',
      noteTotal: '300.000',
      afterDelivery: '1300.000',
      over: true,
    });
  });

  it('refuses a credit position that came without its verdict, rather than showing none as under', async () => {
    const pending = api.credit('c1', 'n1');
    http.expectOne('/api/companies/c1/delivery-notes/n1/credit').flush({ limit: '0.000' });
    await expect(pending).rejects.toThrow();
  });

  it('validates, delivers on a day or today, and cancels', async () => {
    const validated = api.validate('c1', 'n1');
    const validate = http.expectOne('/api/companies/c1/delivery-notes/n1/validate');
    expect(validate.request.method).toBe('POST');
    validate.flush({ id: 'n1', status: 'validated', number: 'BL-2026-00001' });
    expect((await validated).number).toBe('BL-2026-00001');

    const delivered = api.deliver('c1', 'n1', '2026-09-20');
    const deliver = http.expectOne('/api/companies/c1/delivery-notes/n1/deliver');
    expect(deliver.request.body).toEqual({ deliveredOn: '2026-09-20' });
    deliver.flush({ id: 'n1', status: 'delivered' });
    expect((await delivered).status).toBe('delivered');

    const today = api.deliver('c1', 'n1', null);
    http
      .expectOne('/api/companies/c1/delivery-notes/n1/deliver')
      .flush({ id: 'n1', status: 'delivered' }, { status: 200, statusText: 'OK' });
    await today;

    const cancelled = api.cancel('c1', 'n1');
    const cancel = http.expectOne('/api/companies/c1/delivery-notes/n1/cancel');
    expect(cancel.request.method).toBe('POST');
    cancel.flush({ id: 'n1', status: 'cancelled' });
    expect((await cancelled).status).toBe('cancelled');
  });

  it('answers each refusal with the code the screens translate', async () => {
    const conflict = api.validate('c1', 'n1');
    http
      .expectOne('/api/companies/c1/delivery-notes/n1/validate')
      .flush(null, { status: 409, statusText: 'Conflict' });
    await expect(conflict).rejects.toEqual(new DeliveryNotesRefused('conflict'));

    const invalid = api.create('c1', input);
    http
      .expectOne('/api/companies/c1/delivery-notes')
      .flush(null, { status: 422, statusText: 'Unprocessable' });
    await expect(invalid).rejects.toEqual(new DeliveryNotesRefused('invalid'));

    // A customer deactivated since, or no longer the company's, is named by the field the API refused, never its words.
    const gone = api.create('c1', input);
    http
      .expectOne('/api/companies/c1/delivery-notes')
      .flush(
        { detail: 'customerId: The customer CLI-0007 is deactivated.' },
        { status: 422, statusText: 'Unprocessable' },
      );
    await expect(gone).rejects.toEqual(new DeliveryNotesRefused('customer_unavailable'));

    const absent = api.note('c1', 'n9');
    http
      .expectOne('/api/companies/c1/delivery-notes/n9')
      .flush(null, { status: 404, statusText: 'Not Found' });
    await expect(absent).rejects.toEqual(new DeliveryNotesRefused('not_found'));

    const offline = api.notes('c1', SEARCH);
    http
      .expectOne((candidate) => candidate.url === '/api/companies/c1/delivery-notes')
      .error(new ProgressEvent('error'));
    await expect(offline).rejects.toEqual(new DeliveryNotesRefused('network'));
  });

  it('names the PDF of a note', () => {
    expect(api.pdfUrl('c 1', 'n1')).toBe('/api/companies/c%201/delivery-notes/n1/pdf');
  });
  it('drafts an invoice from notes and answers its id', async () => {
    const pending = api.invoice('c1', ['n1']);
    const request = http.expectOne('/api/companies/c1/invoices/from-delivery-notes');
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ deliveryNoteIds: ['n1'] });
    request.flush({ id: 'i7', status: 'draft' });
    expect(await pending).toBe('i7');
  });

  it('drafts an invoice for the quantities given, and says nothing of them when none are', async () => {
    const pending = api.invoice('c1', ['n1'], { l1: '6' });
    const request = http.expectOne('/api/companies/c1/invoices/from-delivery-notes');
    expect(request.request.body).toEqual({ deliveryNoteIds: ['n1'], quantities: { l1: '6' } });
    request.flush({ id: 'i8', status: 'draft' });
    expect(await pending).toBe('i8');
  });

  it("adds the notes to a draft on the draft's own route, with the quantities when there are some", async () => {
    const whole = api.invoice('c1', ['n1'], undefined, 'd 1');
    const request = http.expectOne('/api/companies/c1/invoices/d%201/delivery-notes');
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ deliveryNoteIds: ['n1'] });
    request.flush({ id: 'd 1', status: 'draft' });
    expect(await whole).toBe('d 1');

    const part = api.invoice('c1', ['n1'], { l1: '6' }, 'd2');
    http
      .expectOne('/api/companies/c1/invoices/d2/delivery-notes')
      .flush({ id: 'd2', status: 'draft' });
    await part;
  });

  it("lists the drafts of the notes' customer, leaving out those of another establishment", async () => {
    const row = (id: string, establishmentId: string | null, lines: number) => ({
      id,
      establishmentId,
      total: '10.000',
      customerReference: id === 'd1' ? 'PO-7' : null,
      lines: Array.from({ length: lines }, () => ({})),
    });
    invoices.invoices.mockResolvedValue({
      rows: [row('d1', 'e1', 2), row('d2', 'e2', 1), row('d3', 'e1', 3)],
      total: 3,
    });

    expect(await api.draftsOf('c1', 'k1', 'e1')).toEqual([
      { id: 'd1', total: '10.000', lineCount: 2, customerReference: 'PO-7' },
      { id: 'd3', total: '10.000', lineCount: 3, customerReference: null },
    ]);
    expect(invoices.invoices).toHaveBeenCalledWith('c1', {
      page: 1,
      itemsPerPage: 20,
      q: '',
      status: ['draft'],
      documentType: ['invoice'],
      customerIds: ['k1'],
      intervals: {},
      order: null,
    });
  });

  it('reads what of a note is still to invoice', async () => {
    const pending = api.left('c1', 'n1');
    const request = http.expectOne('/api/companies/c1/delivery-notes/n1/left');
    expect(request.request.method).toBe('GET');
    const lines = [{ lineId: 'l1', quantity: '10.000', invoiced: '6.000', left: '4.000' }];
    request.flush({ deliveryNoteId: 'n1', lines });
    expect(await pending).toEqual({ lines });
  });

  it('turns a refused conversion into a code the screen translates', async () => {
    const pending = api.invoice('c1', ['n1']);
    http
      .expectOne('/api/companies/c1/invoices/from-delivery-notes')
      .flush({}, { status: 409, statusText: 'Conflict' });
    await expect(pending).rejects.toEqual(new DeliveryNotesRefused('conflict'));
  });
});
