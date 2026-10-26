// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { PurchasesApi, PurchasesRefused } from './purchases-api';
import type { PurchaseOrderInput, PurchaseOrderSearch } from './purchases-types';
import { TextsParts } from '../shared/i18n/texts-part';

const draft: PurchaseOrderInput = {
  vendorId: 'v1',
  establishmentId: null,
  expectedOn: '2026-10-25',
  remarksPrinted: null,
  notesInternal: null,
  lines: [{ productId: 'p1', description: null, quantity: '12', unitPriceNet: '2.5' }],
};

const answered = {
  id: 'o 1',
  version: 3,
  number: 'BC-2026-0001',
  status: 'partly_received',
  vendorId: 'v1',
  vendorName: 'Sotumag',
  vendorSnapshot: { number: 'FRN-0001' },
  issueDate: '2026-10-10',
  lines: [
    {
      id: 'l1',
      productId: 'p1',
      productReference: 'VIS-6',
      productTracking: 'lot',
      quantity: '12.000',
      unitId: 'u1',
      unitCode: 'C62',
      unitName: 'pièce',
      unitPriceNet: '2.5000',
      net: '30.000',
      received: '4.000',
      outstanding: '8.000',
    },
  ],
  totalNet: '30.000',
  currencyScale: 3,
  receipts: [
    {
      id: 'r1',
      receivedOn: '2026-10-12',
      supplierReference: 'BL 77',
      lines: [
        {
          orderLineId: 'l1',
          locationId: 's1',
          quantity: '4.000',
          lotCode: 'L-26',
          lotExpiresOn: null,
          unitCost: null,
        },
      ],
    },
  ],
};

describe('PurchasesApi', () => {
  let api: PurchasesApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        // A refused save opens the texts its reasons are said in; their fetch is the texts part's own concern.
        { provide: TextsParts, useValue: { open: vi.fn(async () => undefined) } },
      ],
    });
    api = TestBed.inject(PurchasesApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('asks a page of orders with only what the search names', async () => {
    const search: PurchaseOrderSearch = {
      page: 2,
      itemsPerPage: 25,
      q: ' vis ',
      status: ['sent', 'partly_received'],
      vendorIds: ['v1'],
      intervals: { 'issueDate.from': '2026-10-01' },
      order: { key: 'number', direction: 'desc' },
    };

    const pending = api.orders('c1', search);
    const request = http.expectOne((each) => each.url === '/api/companies/c1/purchase-orders');
    expect(request.request.headers.get('Accept')).toBe('application/ld+json');
    expect(request.request.params.toString()).toBe(
      'page=2&itemsPerPage=25&q=vis&status%5B%5D=sent&status%5B%5D=partly_received&vendorId%5B%5D=v1&issueDate%5Bfrom%5D=2026-10-01&order%5Bnumber%5D=desc',
    );
    request.flush({ member: [answered], totalItems: 26 });

    const page = await pending;
    expect(page.total).toBe(26);
    expect(page.rows[0]).toMatchObject({
      id: 'o 1',
      number: 'BC-2026-0001',
      status: 'partly_received',
      recordedVendorNumber: 'FRN-0001',
      currencyScale: 3,
      lines: [
        { productTracking: 'lot', unitName: 'pièce', received: '4.000', outstanding: '8.000' },
      ],
      receipts: [{ id: 'r1', supplierReference: 'BL 77', lines: [{ lotCode: 'L-26' }] }],
    });
  });

  it('sends the version a revision was opened at', async () => {
    const pending = api.revise('c1', 'o 1', draft, 3);
    const request = http.expectOne('/api/companies/c1/purchase-orders/o%201');
    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual({ ...draft, version: 3 });
    request.flush(answered);
    await pending;
  });

  it('records a receipt against the order and reads the order as it now stands', async () => {
    const pending = api.receive('c1', 'o 1', {
      receivedOn: '2026-10-18',
      supplierReference: 'BL 78',
      apply: null,
      lines: [
        {
          orderLineId: 'l1',
          locationId: 's1',
          quantity: '8',
          lotCode: 'L-27',
          lotExpiresOn: null,
          unitCost: null,
        },
      ],
    });
    const request = http.expectOne('/api/companies/c1/purchase-orders/o%201/receipts');
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toMatchObject({ receivedOn: '2026-10-18', apply: null });
    request.flush({ ...answered, status: 'received' });
    expect((await pending).status).toBe('received');
  });

  it('reads where the goods go, each line’s home keyed by its id', async () => {
    const pending = api.receiving('c1', 'o 1');
    http.expectOne('/api/companies/c1/purchase-orders/o%201/receiving').flush({
      places: [{ id: 's1', parentId: null, code: 'MAG', name: 'Magasin', isDefault: true }],
      homes: [{ orderLineId: 'l1', locationId: 's1' }],
      asksCostBasis: false,
    });
    expect(await pending).toEqual({
      places: [{ id: 's1', parentId: null, code: 'MAG', name: 'Magasin', isDefault: true }],
      homes: { l1: 's1' },
      asksCostBasis: false,
    });
  });

  it.each([
    ['send', 'send'],
    ['close', 'close'],
    ['cancel', 'cancel'],
  ] as const)('posts the %s step with no body', async (method, path) => {
    const pending = api[method]('c1', 'o 1');
    const request = http.expectOne(`/api/companies/c1/purchase-orders/o%201/${path}`);
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toBeNull();
    request.flush(answered);
    await pending;
  });

  it('asks a picker for the records an order already names, by id', async () => {
    const pending = api.pickProducts('c1', { ids: ['p1', 'p2'] });
    const request = http.expectOne(
      (each) => each.url === '/api/companies/c1/purchase-order-options/products',
    );
    expect(request.request.params.getAll('ids[]')).toEqual(['p1', 'p2']);
    request.flush([
      {
        id: 'p1',
        reference: 'VIS-6',
        name: 'Vis 6 mm',
        unitId: 'u1',
        unitName: 'pièce',
        tracking: 'serial',
        goods: false,
        active: true,
        costPrice: null,
      },
    ]);
    expect(await pending).toEqual([
      {
        id: 'p1',
        reference: 'VIS-6',
        name: 'Vis 6 mm',
        unitId: 'u1',
        unitName: 'pièce',
        tracking: 'serial',
        goods: false,
        active: true,
        costPrice: null,
      },
    ]);
  });

  it.each([
    [404, {}, 'not_found', null],
    [409, {}, 'conflict', null],
    [409, { type: '/problems/record-changed' }, 'record_changed', null],
    [422, { detail: 'vendorId: This vendor is retired.' }, 'vendor_unavailable', 'vendorId'],
    [422, { detail: 'lines[1].quantity: Too fine.' }, 'invalid', 'lines[1].quantity'],
  ] as const)('reads a %s answer as its code and field', async (status, body, code, field) => {
    const pending = api.create('c1', draft);
    http
      .expectOne('/api/companies/c1/purchase-orders')
      .flush(body, { status, statusText: 'Refused' });

    const refused = await pending.then(
      () => null,
      (error: unknown) => error,
    );
    expect(refused).toBeInstanceOf(PurchasesRefused);
    expect(refused).toMatchObject({ code, field });
  });

  it('names each field a save was refused on, a line’s by its line', async () => {
    const refused = api.revise('c1', 'o1', draft, 2);
    http.expectOne('/api/companies/c1/purchase-orders/o1').flush(
      {
        detail: 'lines[0].quantity: A line orders a positive quantity.',
        violations: [
          {
            propertyPath: 'lines[0].quantity',
            message: 'x',
            payload: { reason: 'quantity_above_zero' },
          },
        ],
      },
      { status: 422, statusText: 'Unprocessable Content' },
    );
    await expect(refused).rejects.toMatchObject({
      code: 'invalid',
      field: 'lines[0].quantity',
      refusals: [{ field: 'lines[0].quantity', reason: 'quantity_above_zero', params: {} }],
    });
  });

  it('reads an unreachable API as a network failure', async () => {
    const pending = api.order('c1', 'o 1');
    http
      .expectOne('/api/companies/c1/purchase-orders/o%201')
      .error(new ProgressEvent('error'), { status: 0 });
    await expect(pending).rejects.toMatchObject({ code: 'network' });
  });
});
