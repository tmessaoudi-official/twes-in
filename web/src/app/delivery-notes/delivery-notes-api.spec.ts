// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
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
  status: null,
  customerId: null,
  order: null,
} as const;

describe('DeliveryNotesApi', () => {
  let api: DeliveryNotesApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
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
      status: 'validated',
      customerId: 'k1',
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
    expect(request.request.params.get('status')).toBe('validated');
    expect(request.request.params.get('customerId')).toBe('k1');
    expect(request.request.params.get('order[deliveryDate]')).toBe('asc');
    request.flush({ member: [{ id: 'n1', number: 'BL-2026-00001' }], totalItems: 91 });

    const page = await pending;
    expect(page.rows.map((row) => row.number)).toEqual(['BL-2026-00001']);
    expect(page.total).toBe(91);
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

  it('turns a refused conversion into a code the screen translates', async () => {
    const pending = api.invoice('c1', ['n1']);
    http
      .expectOne('/api/companies/c1/invoices/from-delivery-notes')
      .flush({}, { status: 409, statusText: 'Conflict' });
    await expect(pending).rejects.toEqual(new DeliveryNotesRefused('conflict'));
  });
});
