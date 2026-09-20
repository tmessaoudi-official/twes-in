// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { InventoryApi } from './inventory-api';
import type { StockLocationInput, StockLocationRow, StockMovementRow } from './inventory-types';

const zone: StockLocationRow = {
  id: 'l2',
  establishmentId: 'e1',
  parentId: 'l1',
  kind: 'zone',
  code: 'Z1',
  name: 'Zone froide',
  isDefault: false,
  childCount: 1,
  movementCount: 4,
};

/** A search that asks for nothing but the first page, so a case names only what it changes. */
const SEARCH = {
  page: 1,
  itemsPerPage: 25,
  q: '',
  locationId: null,
  establishmentId: null,
  order: null,
} as const;

const received: StockMovementRow = {
  id: 'm1',
  productId: 'p1',
  productReference: 'ART-1',
  productName: 'Portable',
  unitCode: 'C62',
  unitDecimals: 0,
  locationId: 'l1',
  locationCode: '000',
  locationName: 'Siège',
  kind: 'in',
  quantity: '10.000',
  sourceType: 'receipt',
  sourceId: null,
  recordedBy: 'u1',
  at: '2026-09-15T09:00:00+00:00',
};

describe('InventoryApi', () => {
  let api: InventoryApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(InventoryApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('reads the options, the stock and the locations', async () => {
    const options = api.options('c 1');
    http.expectOne('/api/companies/c%201/stock-options').flush({
      establishments: [{ id: 'e1', code: '000', name: 'Siège' }],
    });
    expect(await options).toEqual({
      establishments: [{ id: 'e1', code: '000', name: 'Siège' }],
    });

    const levels = api.levels('c1', SEARCH);
    const level = {
      id: 'p1:l1',
      productId: 'p1',
      productReference: 'ART-1',
      productName: 'Portable',
      unitCode: 'C62',
      unitDecimals: 0,
      locationId: 'l1',
      locationCode: '000',
      locationName: 'Siège',
      establishmentId: 'e1',
      quantity: '-2.000',
    };
    http
      .expectOne((candidate) => candidate.url === '/api/companies/c1/stock-levels')
      .flush({ member: [level], totalItems: 7 });
    expect(await levels).toEqual({ rows: [level], total: 7 });

    const locations = api.locations('c1');
    http.expectOne('/api/companies/c1/stock-locations').flush([{ ...zone, kind: 'cellar' }]);
    expect(await locations).toEqual([{ ...zone, kind: 'zone' }]);
  });

  it('asks the picker for the few stocked products a person means, and by id for a named one', async () => {
    const searched = api.pickProducts('c1', { words: '  port ' });
    const search = http.expectOne(
      (request) => request.url === '/api/companies/c1/stock-options/products',
    );
    expect(search.request.params.get('q')).toBe('port');
    search.flush([
      { id: 'p1', reference: 'ART-1', name: 'Portable', unitCode: 'C62', unitDecimals: 0 },
    ]);
    expect(await searched).toEqual([
      { id: 'p1', reference: 'ART-1', name: 'Portable', unitCode: 'C62', unitDecimals: 0 },
    ]);

    const named = api.pickProducts('c1', { ids: ['p1', 'p2'] });
    const resolve = http.expectOne(
      (request) => request.url === '/api/companies/c1/stock-options/products',
    );
    // Asking for what a movement names is not a search: the words are left out entirely.
    expect(resolve.request.params.getAll('ids[]')).toEqual(['p1', 'p2']);
    expect(resolve.request.params.has('q')).toBe(false);
    resolve.flush([]);
    expect(await named).toEqual([]);
  });

  it("reads one product's movements, or the company's latest", async () => {
    const one = api.movements('c1', 'p 1');
    http
      .expectOne(
        (request) => request.urlWithParams === '/api/companies/c1/stock-movements?productId=p%201',
      )
      .flush([received]);
    expect(await one).toEqual([received]);

    const all = api.movements('c1', null);
    http.expectOne('/api/companies/c1/stock-movements').flush([]);
    expect(await all).toEqual([]);
  });

  it('creates, revises and deletes a location, and says a code is taken or a location in use', async () => {
    const input: StockLocationInput = {
      establishmentId: 'e1',
      parentId: null,
      kind: 'zone',
      code: 'Z1',
      name: 'Zone froide',
    };

    const created = api.createLocation('c1', input);
    const post = http.expectOne('/api/companies/c1/stock-locations');
    expect([post.request.method, post.request.body]).toEqual(['POST', input]);
    post.flush(zone);
    expect(await created).toEqual(zone);

    const revised = api.reviseLocation('c1', 'l2', input);
    const put = http.expectOne('/api/companies/c1/stock-locations/l2');
    expect(put.request.method).toBe('PUT');
    put.flush({ detail: 'taken' }, { status: 409, statusText: 'Conflict' });
    await expect(revised).rejects.toMatchObject({ code: 'code_taken' });

    const deleted = api.deleteLocation('c1', 'l2');
    const del = http.expectOne('/api/companies/c1/stock-locations/l2');
    expect(del.request.method).toBe('DELETE');
    del.flush({ detail: 'in use' }, { status: 409, statusText: 'Conflict' });
    await expect(deleted).rejects.toMatchObject({ code: 'in_use' });
  });

  it('records a receipt or a count, and says why one was refused', async () => {
    const recorded = api.record('c1', {
      operation: 'receive',
      productId: 'p1',
      locationId: 'l1',
      quantity: '10',
    });
    const post = http.expectOne('/api/companies/c1/stock-movements');
    expect([post.request.method, post.request.body]).toEqual([
      'POST',
      { operation: 'receive', productId: 'p1', locationId: 'l1', quantity: '10' },
    ]);
    post.flush(received);
    expect(await recorded).toEqual(received);

    const refused = api.record('c1', {
      operation: 'count',
      productId: 'p1',
      locationId: 'l1',
      quantity: '1.5',
    });
    http
      .expectOne('/api/companies/c1/stock-movements')
      .flush({ detail: 'quantity' }, { status: 422, statusText: 'Unprocessable Entity' });
    await expect(refused).rejects.toMatchObject({ code: 'invalid' });

    const unreachable = api.levels('c1', SEARCH);
    http
      .expectOne((candidate) => candidate.url === '/api/companies/c1/stock-levels')
      .error(new ProgressEvent('error'));
    await expect(unreachable).rejects.toMatchObject({ code: 'network' });

    const gone = api.levels('c1', SEARCH);
    http
      .expectOne((candidate) => candidate.url === '/api/companies/c1/stock-levels')
      .flush({}, { status: 404, statusText: 'Not Found' });
    await expect(gone).rejects.toMatchObject({ code: 'not_found' });
  });

  it('asks the API for one page of stock, with what it searches, narrows and sorts by', async () => {
    const pending = api.levels('c1', {
      ...SEARCH,
      page: 2,
      itemsPerPage: 50,
      q: '  portable  ',
      locationId: 'l1',
      establishmentId: 'e1',
      order: { key: 'quantity', direction: 'desc' },
    });
    const request = http.expectOne(
      (candidate) =>
        candidate.url === '/api/companies/c1/stock-levels' && candidate.method === 'GET',
    );
    expect(request.request.headers.get('Accept')).toBe('application/ld+json');
    expect(request.request.params.get('page')).toBe('2');
    expect(request.request.params.get('itemsPerPage')).toBe('50');
    // Trimmed, so a trailing space is not a different search.
    expect(request.request.params.get('q')).toBe('portable');
    expect(request.request.params.get('locationId')).toBe('l1');
    expect(request.request.params.get('establishmentId')).toBe('e1');
    expect(request.request.params.get('order[quantity]')).toBe('desc');
    request.flush({ member: [], totalItems: 0 });
    expect(await pending).toEqual({ rows: [], total: 0 });
  });

  it('refuses a page that came without its total, rather than showing one page as the whole stock', async () => {
    const pending = api.levels('c1', SEARCH);
    const request = http.expectOne(
      (candidate) =>
        candidate.url === '/api/companies/c1/stock-levels' && candidate.method === 'GET',
    );
    expect(request.request.params.has('q')).toBe(false);
    expect(request.request.params.has('locationId')).toBe(false);
    request.flush({ member: [] });
    await expect(pending).rejects.toThrow();
  });
});
