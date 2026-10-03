// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { CustomerScreenApi } from './customer-screen-api';

const found = {
  items: [
    { id: 'p1', name: 'Vis', reference: 'ART-001', barcode: '3017620422003', finalPrice: '21.420' },
    { id: 'p2', name: 'Écrou', reference: 'ART-002', barcode: null, finalPrice: '5.000' },
  ],
};

describe('CustomerScreenApi', () => {
  let api: CustomerScreenApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(CustomerScreenApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  const answer = async (
    promotions: object | null,
    availability: object | null,
  ): Promise<ReturnType<CustomerScreenApi['find']>> => {
    const read = api.find('c1', 'vis');
    http.expectOne((r) => r.url === '/api/companies/c1/customer-screen/products').flush(found);
    // The two optional answers are asked once the products are known, for those products only.
    await Promise.resolve();
    await Promise.resolve();
    const promos = http.expectOne((r) => r.url === '/api/companies/c1/customer-screen/promotions');
    const stock = http.expectOne((r) => r.url === '/api/companies/c1/customer-screen/availability');
    expect(promos.request.params.getAll('ids[]')).toEqual(['p1', 'p2']);
    expect(stock.request.params.getAll('ids[]')).toEqual(['p1', 'p2']);
    if (promotions === null) promos.flush({}, { status: 404, statusText: 'Not Found' });
    else promos.flush(promotions);
    if (availability === null) stock.flush({}, { status: 404, statusText: 'Not Found' });
    else stock.flush(availability);
    return read;
  };

  it('asks the words, then joins what each module says of the products found', async () => {
    const rows = await answer(
      {
        items: [
          {
            productId: 'p1',
            price: '17.850',
            minQuantity: '10.000',
            startsOn: null,
            endsOn: '2026-10-09',
          },
        ],
      },
      {
        items: [
          { productId: 'p1', inStock: true },
          { productId: 'p2', inStock: false },
        ],
      },
    );

    expect(rows).toEqual([
      {
        id: 'p1',
        name: 'Vis',
        reference: 'ART-001',
        barcode: '3017620422003',
        finalPrice: '21.420',
        inStock: true,
        promotions: [
          { price: '17.850', minQuantity: '10.000', startsOn: null, endsOn: '2026-10-09' },
        ],
      },
      {
        id: 'p2',
        name: 'Écrou',
        reference: 'ART-002',
        barcode: null,
        finalPrice: '5.000',
        inStock: false,
        promotions: [],
      },
    ]);
  });

  it('says nothing of stock a company keeps to itself, and nothing of promotions when its module is off', async () => {
    const rows = await answer(null, { items: [] });

    expect(rows.map((row) => [row.inStock, row.promotions])).toEqual([
      [null, []],
      [null, []],
    ]);
  });

  it('does not ask for either when nothing was found', async () => {
    const read = api.find('c1', 'nothing');
    http
      .expectOne((r) => r.url === '/api/companies/c1/customer-screen/products')
      .flush({ items: [] });

    expect(await read).toEqual([]);
  });

  it('lets a failure that is not a missing module through, since it would hide a wrong answer', async () => {
    const read = api.find('c1', 'vis');
    http.expectOne((r) => r.url === '/api/companies/c1/customer-screen/products').flush(found);
    await Promise.resolve();
    await Promise.resolve();
    http
      .expectOne((r) => r.url === '/api/companies/c1/customer-screen/promotions')
      .flush({}, { status: 500, statusText: 'Server Error' });
    http
      .expectOne((r) => r.url === '/api/companies/c1/customer-screen/availability')
      .flush({ items: [] });

    await expect(read).rejects.toMatchObject({ status: 500 });
  });
});
