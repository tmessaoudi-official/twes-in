// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { PriceListsApi, PriceListsRefused } from './price-lists-api';
import type { PriceListInput } from './price-lists-types';

const read = {
  id: 'l1',
  name: 'Gros',
  customerGroupId: 'g1',
  customerId: null,
  validFrom: '2026-01-01',
  validTo: null,
  isActive: true,
  itemCount: 1,
  items: [
    {
      productId: 'p1',
      productReference: 'REF-1',
      productName: 'Stylo',
      minQuantity: '10.000',
      unitPriceNet: '0.9000',
    },
  ],
};

const input: PriceListInput = {
  name: 'Gros',
  customerGroupId: 'g1',
  customerId: null,
  validFrom: '2026-01-01',
  validTo: null,
  isActive: true,
  items: [
    {
      productId: 'p1',
      productReference: 'REF-1',
      productName: 'Stylo',
      minQuantity: '10.000',
      unitPriceNet: '0.9000',
    },
  ],
};

describe('PriceListsApi', () => {
  let api: PriceListsApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(PriceListsApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('lists the lists without their prices, carrying only how many each holds', async () => {
    const lists = api.list('c/1');
    http.expectOne('/api/companies/c%2F1/price-lists').flush([{ ...read, items: undefined }]);

    const [row] = await lists;
    expect(row.itemCount).toBe(1);
    expect(row.items).toBeNull();
  });

  it('reads one list with its prices', async () => {
    const one = api.get('c1', 'l1');
    http.expectOne('/api/companies/c1/price-lists/l1').flush(read);

    expect((await one).items).toEqual(input.items);
  });

  it('sends the whole list, its prices without the words a screen shows', async () => {
    const saved = api.create('c1', input);
    const request = http.expectOne('/api/companies/c1/price-lists');
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({
      name: 'Gros',
      customerGroupId: 'g1',
      customerId: null,
      validFrom: '2026-01-01',
      validTo: null,
      isActive: true,
      items: [{ productId: 'p1', minQuantity: '10.000', unitPriceNet: '0.9000' }],
    });
    request.flush(read);
    await saved;
  });

  it('leaves the prices out of a save that does not name them, so the API keeps them', async () => {
    const saved = api.revise('c1', 'l1', { ...input, items: null });
    const request = http.expectOne('/api/companies/c1/price-lists/l1');
    expect(request.request.method).toBe('PUT');
    expect('items' in request.request.body).toBe(false);
    request.flush(read);
    await saved;
  });

  it('deletes a list', async () => {
    const gone = api.remove('c1', 'l1');
    const request = http.expectOne('/api/companies/c1/price-lists/l1');
    expect(request.request.method).toBe('DELETE');
    request.flush(null, { status: 204, statusText: 'No Content' });
    await expect(gone).resolves.toBeUndefined();
  });

  it('names a refusal: a taken name, a missing list, a refused row, a denied right, no network', async () => {
    const answers: [number, string][] = [
      [409, 'name_taken'],
      [404, 'not_found'],
      [422, 'invalid'],
      [403, 'refused'],
    ];
    for (const [status, code] of answers) {
      const call = api.create('c1', input);
      http.expectOne('/api/companies/c1/price-lists').flush({}, { status, statusText: 'x' });
      await expect(call).rejects.toEqual(new PriceListsRefused(code as never));
    }
    const offline = api.list('c1');
    http
      .expectOne('/api/companies/c1/price-lists')
      .error(new ProgressEvent('error'), { status: 0 });
    await expect(offline).rejects.toEqual(new PriceListsRefused('network'));
  });

  it('asks for the price a product sells at for a customer and a quantity, null where unreadable', async () => {
    const price = api.productPrice('c1', 'p/1', 'k1', '10');
    const request = http.expectOne((req) => req.url === '/api/companies/c1/products/p%2F1/price');
    expect(request.request.params.get('customerId')).toBe('k1');
    expect(request.request.params.get('quantity')).toBe('10');
    request.flush({ productId: 'p/1', unitPriceNet: '1500.0000', priceListName: 'Gros' });
    expect(await price).toEqual({ unitPriceNet: '1500.0000', priceListName: 'Gros' });

    const gone = api.productPrice('c1', 'p1', null, '1');
    const second = http.expectOne((req) => req.url === '/api/companies/c1/products/p1/price');
    expect(second.request.params.has('customerId')).toBe(false);
    second.flush({}, { status: 404, statusText: 'Not Found' });
    expect(await gone).toBeNull();
  });
});
