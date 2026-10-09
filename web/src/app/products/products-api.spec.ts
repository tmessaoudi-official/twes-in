// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ProductsApi, ProductsRefused } from './products-api';
import type { ProductInput, ProductSearch } from './products-types';

const everyProduct: ProductSearch = {
  page: 1,
  itemsPerPage: 25,
  q: '',
  kinds: [],
  trackings: [],
  categoryIds: [],
  isActive: null,
  intervals: {},
  order: null,
};

const laptop: ProductInput = {
  reference: 'ART-001',
  name: 'Portable 14"',
  description: null,
  kind: 'goods',
  unitId: 'u1',
  unitPriceNet: '1250.5',
  costPrice: null,
  categoryId: 'k1',

  defaultTaxComponentIds: ['t1'],
  isActive: true,
  customFields: { warranty: 24 },
  tracking: 'none',
  substitutionGroup: null,
};

describe('ProductsApi', () => {
  let api: ProductsApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(ProductsApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('reads the cost history of a product, the latest first, naming what a missing field means', async () => {
    const read = api.costHistory('c1', 'p1');
    http.expectOne('/api/companies/c1/products/p1/cost-history').flush([
      {
        id: 'h2',
        oldCost: '1000.0000',
        newCost: '1300.0000',
        source: 'receipt',
        at: '2026-10-03T13:20:24+00:00',
      },
      {
        id: 'h1',
        oldCost: null,
        newCost: '1000.0000',
        source: 'created',
        at: '2026-10-01T09:00:00+00:00',
      },
    ]);
    expect(await read).toEqual([
      {
        id: 'h2',
        oldCost: '1000.0000',
        newCost: '1300.0000',
        source: 'receipt',
        at: '2026-10-03T13:20:24+00:00',
      },
      {
        id: 'h1',
        oldCost: null,
        newCost: '1000.0000',
        source: 'created',
        at: '2026-10-01T09:00:00+00:00',
      },
    ]);
  });

  it('names the file of what the list shows, with its words, choices and order and no page', () => {
    const search = {
      page: 2,
      itemsPerPage: 50,
      q: ' vis ',
      kinds: ['goods' as const],
      trackings: [],
      categoryIds: [],
      isActive: false,
      intervals: { 'unitPriceNet.min': '10' },
      order: { key: 'name' as const, direction: 'asc' as const },
    };

    expect(api.exportUrl('c/1', search, 'csv')).toBe(
      '/api/companies/c%2F1/exports/products.csv?q=vis&kind%5B%5D=goods&isActive=false&unitPriceNet%5Bmin%5D=10&order%5Bname%5D=asc',
    );
  });

  it('reads the substitutes of a product and the stock on hand of products, listing every id asked for', async () => {
    const read = api.substitutes('c1', 'p1');
    http
      .expectOne('/api/companies/c1/products/p1/substitutes')
      .flush([
        { id: 'p2', reference: 'ART-002', name: 'Souris', isActive: true, unitPriceNet: '10.0000' },
      ]);
    expect(await read).toEqual([
      {
        id: 'p2',
        reference: 'ART-002',
        name: 'Souris',
        isActive: true,
        unitPriceNet: '10.0000',
        onHand: null,
      },
    ]);

    const totals = api.stockTotals('c1', ['p2', 'p3']);
    http
      .expectOne((request) => request.url === '/api/companies/c1/stock-totals')
      .flush([
        { productId: 'p2', quantity: '7.000' },
        { productId: 'p3', quantity: '0.000' },
      ]);
    expect([...(await totals)]).toEqual([
      ['p2', '7.000'],
      ['p3', '0.000'],
    ]);
    expect((await api.stockTotals('c1', [])).size).toBe(0);
  });

  // docs/SPEC.md § 7, 2026-09-24 11:40.
  it('reads the homes with their order, and writes one establishment’s homes as a whole list', async () => {
    const read = api.homes('c1', 'p1');
    http.expectOne('/api/companies/c1/products/p1/home-locations').flush([
      {
        id: 'h1',
        establishmentId: 'e1',
        establishmentCode: 'SIEGE',
        establishmentName: 'Siège',
        locationId: 'l1',
        locationCode: 'A-12',
        locationName: 'Zone A-12',
        position: 0,
        main: true,
      },
      { id: 'h2', establishmentId: 'e1', locationId: 'l2' },
    ]);
    const rows = await read;
    expect(rows.map((row) => [row.locationId, row.position, row.main])).toEqual([
      ['l1', 0, true],
      ['l2', 0, false],
    ]);

    const written = api.replaceHomes('c1', 'p 1', 'e/1', ['l2', 'l1']);
    const put = http.expectOne('/api/companies/c1/products/p%201/home-locations/e%2F1');
    expect(put.request.method).toBe('PUT');
    expect(put.request.body).toEqual({ locationIds: ['l2', 'l1'] });
    put.flush(null, { status: 204, statusText: 'No Content' });
    await expect(written).resolves.toBeUndefined();

    const refused = api.replaceHomes('c1', 'p1', 'e1', ['elsewhere']);
    http
      .expectOne('/api/companies/c1/products/p1/home-locations/e1')
      .flush(null, { status: 422, statusText: 'Unprocessable' });
    await expect(refused).rejects.toBeInstanceOf(ProductsRefused);
  });

  it('reads, sets and clears a reorder point per establishment', async () => {
    const read = api.reorderPoints('c1', 'p1');
    http.expectOne('/api/companies/c1/products/p1/reorder-points').flush([
      {
        id: 'r1',
        establishmentId: 'e1',
        establishmentCode: 'SIEGE',
        establishmentName: 'Siège',
        quantity: '12.000',
      },
      {
        id: null,
        establishmentId: 'e2',
        establishmentCode: 'SFAX',
        establishmentName: 'Sfax',
        quantity: null,
      },
    ]);
    expect((await read).map((row) => [row.establishmentId, row.quantity])).toEqual([
      ['e1', '12.000'],
      ['e2', null],
    ]);

    const set = api.setReorderPoint('c1', 'p1', 'e2', '2.5');
    const put = http.expectOne('/api/companies/c1/products/p1/reorder-points/e2');
    expect([put.request.method, put.request.body]).toEqual(['PUT', { quantity: '2.5' }]);
    put.flush({
      establishmentId: 'e2',
      establishmentCode: 'SFAX',
      establishmentName: 'Sfax',
      quantity: '2.500',
    });
    expect((await set).quantity).toBe('2.500');

    const cleared = api.clearReorderPoint('c1', 'p1', 'e2');
    const del = http.expectOne('/api/companies/c1/products/p1/reorder-points/e2');
    expect(del.request.method).toBe('DELETE');
    del.flush(null);
    await cleared;
  });

  it('reads the form options, keeping only line taxes it knows', async () => {
    const pending = api.options('c 1');
    http.expectOne('/api/companies/c%201/product-options').flush({
      currency: 'TND',
      currencyScale: 3,
      units: [{ id: 'u1', code: 'C62', name: 'Unité', decimals: 0 }],
      taxes: [
        { id: 't1', code: 'TVA19', name: 'TVA 19 %', family: 'vat' },
        { id: 't9', code: 'X', name: 'Inconnue', family: 'stamp' },
      ],
      photosPerProduct: 6,
      photoMaxBytes: 5242880,
    });

    expect(await pending).toEqual({
      currency: 'TND',
      currencyScale: 3,
      units: [{ id: 'u1', code: 'C62', name: 'Unité', decimals: 0 }],
      taxes: [{ id: 't1', code: 'TVA19', name: 'TVA 19 %', family: 'vat' }],
      photosPerProduct: 6,
      photoMaxBytes: 5242880,
    });
  });

  it('asks which reference a new product would be given, in its category when it has one', async () => {
    const plain = api.referencePreview('c1', null);
    http.expectOne('/api/companies/c1/product-reference-preview').flush({ reference: 'ART-00042' });
    expect(await plain).toBe('ART-00042');

    const filed = api.referencePreview('c1', 'k1');
    http
      .expectOne('/api/companies/c1/product-reference-preview?categoryId=k1')
      .flush({ reference: 'BOI-0001' });
    expect(await filed).toBe('BOI-0001');
  });

  it('reads a product with nulls where the API sent none', async () => {
    const pending = api.product('c1', 'p 1');
    http.expectOne('/api/companies/c1/products/p%201').flush({
      id: 'p 1',
      reference: 'ART-001',
      name: 'Portable',
      kind: 'service',
      unitId: 'u1',
      unitPriceNet: '10.0000',
      isActive: false,
    });

    expect(await pending).toEqual({
      id: 'p 1',
      reference: 'ART-001',
      name: 'Portable',
      description: null,
      kind: 'service',
      unitId: 'u1',
      unitPriceNet: '10.0000',
      costPrice: null,
      categoryId: null,
      barcodes: [],
      defaultTaxComponentIds: [],
      isActive: false,
      customFields: {},
      tracking: 'none',
      substitutionGroup: null,
      mainPhotoId: null,
    });
  });

  it('reads how a product is tracked, and an unknown answer as none', async () => {
    const serial = api.product('c1', 'p1');
    http
      .expectOne('/api/companies/c1/products/p1')
      .flush({ ...laptop, id: 'p1', tracking: 'serial' });
    expect((await serial).tracking).toBe('serial');

    const odd = api.product('c1', 'p1');
    http
      .expectOne('/api/companies/c1/products/p1')
      .flush({ ...laptop, id: 'p1', tracking: 'batch' });
    expect((await odd).tracking).toBe('none');
  });

  it('writes the codes of a product as one list and reads back what the API kept', async () => {
    const codes = [
      { role: 'unit' as const, code: '3017620422003', quantity: 1, supplierId: null },
      { role: 'pack' as const, code: '13017620422000', quantity: 12, supplierId: null },
    ];
    const saved = api.replaceBarcodes('c1', 'p 1', codes);
    const put = http.expectOne('/api/companies/c1/products/p%201/barcodes');
    expect(put.request.method).toBe('PUT');
    expect(put.request.body).toEqual({ barcodes: codes });
    put.flush({ productId: 'p 1', barcodes: codes });

    expect(await saved).toEqual(codes);
  });

  it('reads what one scan names, sending the scan as it came, and answers null when no product holds it', async () => {
    const scanned = api.scan('c1', ']C10113017620422000\x1d10LOT 7');
    const get = http.expectOne(
      (req) =>
        req.url === '/api/companies/c1/product-scan' &&
        req.params.get('code') === ']C10113017620422000\x1d10LOT 7',
    );
    get.flush({
      productId: 'p1',
      reference: 'ART-001',
      name: 'Pâte',
      isActive: true,
      code: '13017620422000',
      role: 'pack',
      quantity: 12,
      lot: 'LOT 7',
      unitPriceNet: '12.500',
      mainPhotoId: 'ph1',
    });
    expect(await scanned).toEqual({
      productId: 'p1',
      reference: 'ART-001',
      name: 'Pâte',
      isActive: true,
      code: '13017620422000',
      role: 'pack',
      quantity: 12,
      lot: 'LOT 7',
      useBy: null,
      serial: null,
      unitPriceNet: '12.500',
      mainPhotoId: 'ph1',
    });

    const none = api.scan('c1', '999');
    http
      .expectOne((req) => req.url === '/api/companies/c1/product-scan')
      .flush(null, { status: 404, statusText: 'Not Found' });
    expect(await none).toBeNull();

    const down = api.scan('c1', '999');
    http
      .expectOne((req) => req.url === '/api/companies/c1/product-scan')
      .flush(null, { status: 500, statusText: 'Error' });
    await expect(down).rejects.toEqual(new ProductsRefused('invalid'));
  });

  it('names the row a refusal is about, and whose code it is when another product holds it', async () => {
    const taken = api.replaceBarcodes('c1', 'p1', []);
    http
      .expectOne('/api/companies/c1/products/p1/barcodes')
      .flush(
        { detail: 'barcodes.1.code: 03017620422003 is already a code of ART-001.' },
        { status: 409, statusText: 'Conflict' },
      );
    await expect(taken).rejects.toMatchObject({
      refusal: { code: 'barcode_taken', index: 1, field: 'code', heldBy: 'ART-001' },
    });

    const shape = api.replaceBarcodes('c1', 'p1', []);
    http
      .expectOne('/api/companies/c1/products/p1/barcodes')
      .flush(
        { violations: [{ propertyPath: 'barcodes[2].role', message: 'Choose a valid role.' }] },
        { status: 422, statusText: 'Unprocessable' },
      );
    await expect(shape).rejects.toMatchObject({
      refusal: { code: 'invalid', index: 2, field: 'role', heldBy: null },
    });
  });

  it('creates and revises a product with the body the API takes', async () => {
    const created = api.createProduct('c1', laptop);
    const post = http.expectOne('/api/companies/c1/products');
    expect(post.request.method).toBe('POST');
    expect(post.request.body).toEqual(laptop);
    post.flush({ ...laptop, id: 'p1', unitPriceNet: '1250.5000' });
    expect((await created).unitPriceNet).toBe('1250.5000');

    const revised = api.reviseProduct('c1', 'p1', laptop);
    const put = http.expectOne('/api/companies/c1/products/p1');
    expect(put.request.method).toBe('PUT');
    put.flush({ ...laptop, id: 'p1' });
    await revised;
  });

  it('reads one page of products as the API searched, narrowed and sorted it, with the total', async () => {
    const pending = api.products('c1', {
      page: 2,
      itemsPerPage: 50,
      q: ' écran ',
      kinds: ['goods', 'service'],
      trackings: ['lot', 'serial'],
      categoryIds: ['k1'],
      isActive: false,
      intervals: { 'unitPriceNet.min': '5', 'unitPriceNet.max': '99.5' },
      order: { key: 'category', direction: 'desc' },
    });
    const request = http.expectOne((req) => req.url === '/api/companies/c1/products');
    expect(request.request.headers.get('Accept')).toBe('application/ld+json');
    expect(request.request.params.toString()).toBe(
      'page=2&itemsPerPage=50&q=%C3%A9cran&kind%5B%5D=goods&kind%5B%5D=service&tracking%5B%5D=lot&tracking%5B%5D=serial&categoryId%5B%5D=k1&isActive=false&unitPriceNet%5Bmin%5D=5&unitPriceNet%5Bmax%5D=99.5&order%5Bcategory%5D=desc',
    );
    request.flush({ member: [{ ...laptop, id: 'p1' }], totalItems: 77 });

    const page = await pending;
    expect(page.total).toBe(77);
    expect(page.rows.map((row) => row.reference)).toEqual(['ART-001']);
  });

  it('leaves out of the query what the list does not narrow by', async () => {
    const pending = api.products('c1', everyProduct);
    const request = http.expectOne((req) => req.url === '/api/companies/c1/products');
    expect(request.request.params.toString()).toBe('page=1&itemsPerPage=25');
    request.flush({ member: [], totalItems: 0 });

    await expect(pending).resolves.toEqual({ rows: [], total: 0 });
  });

  it('answers each refusal with the code the screens translate', async () => {
    const kept = api.reviseProduct('c1', 'p1', { ...laptop, tracking: 'lot' });
    http
      .expectOne('/api/companies/c1/products/p1')
      .flush(
        { detail: 'tracking: Stock of ART-001 was moved without lots, so it stays that way.' },
        { status: 422, statusText: 'Unprocessable' },
      );
    await expect(kept).rejects.toEqual(new ProductsRefused('tracking_kept'));

    const taken = api.createProduct('c1', laptop);
    http
      .expectOne('/api/companies/c1/products')
      .flush(null, { status: 409, statusText: 'Conflict' });
    await expect(taken).rejects.toEqual(new ProductsRefused('reference_taken'));

    const invalid = api.reviseProduct('c1', 'p1', laptop);
    http
      .expectOne('/api/companies/c1/products/p1')
      .flush(null, { status: 422, statusText: 'Unprocessable' });
    await expect(invalid).rejects.toEqual(new ProductsRefused('invalid'));

    const hidden = api.products('c1', everyProduct);
    http
      .expectOne((req) => req.url === '/api/companies/c1/products')
      .flush(null, { status: 404, statusText: 'Not Found' });
    await expect(hidden).rejects.toEqual(new ProductsRefused('not_found'));

    const offline = api.products('c1', everyProduct);
    http
      .expectOne((req) => req.url === '/api/companies/c1/products')
      .error(new ProgressEvent('error'));
    await expect(offline).rejects.toEqual(new ProductsRefused('network'));
  });

  it('manages categories, a taken name and a category in use each saying so', async () => {
    const list = api.categories('c1');
    http
      .expectOne('/api/companies/c1/product-categories')
      .flush([{ id: 'k1', name: 'Matériel', productCount: 2, childCount: 1 }]);
    expect(await list).toEqual([
      { id: 'k1', name: 'Matériel', parentId: null, productCount: 2, childCount: 1 },
    ]);

    const created = api.createCategory('c1', { name: 'Portables', parentId: 'k1' });
    const post = http.expectOne('/api/companies/c1/product-categories');
    expect(post.request.body).toEqual({ name: 'Portables', parentId: 'k1' });
    post.flush(null, { status: 409, statusText: 'Conflict' });
    await expect(created).rejects.toEqual(new ProductsRefused('name_taken'));

    const revised = api.reviseCategory('c1', 'k 2', { name: 'Portables', parentId: null });
    const put = http.expectOne('/api/companies/c1/product-categories/k%202');
    expect(put.request.method).toBe('PUT');
    put.flush({ id: 'k 2', name: 'Portables', parentId: null, productCount: 0, childCount: 0 });
    expect((await revised).id).toBe('k 2');

    const removed = api.deleteCategory('c1', 'k1');
    const del = http.expectOne('/api/companies/c1/product-categories/k1');
    expect(del.request.method).toBe('DELETE');
    del.flush(null, { status: 409, statusText: 'Conflict' });
    await expect(removed).rejects.toEqual(new ProductsRefused('in_use'));
  });

  it('asks the API what a price comes to with its taxes, one line per quantity, saving nothing', async () => {
    const counted = api.pricePreview('c1', '100.000', ['t1', 't2'], ['1', '12']);
    const post = http.expectOne('/api/companies/c1/price-preview');
    expect(post.request.method).toBe('POST');
    expect(post.request.body).toEqual({
      unitPriceNet: '100.000',
      taxComponentIds: ['t1', 't2'],
      quantities: ['1', '12'],
    });
    post.flush({
      prices: [
        { quantity: '1', net: '100.000', tax: '20.190', total: '120.190' },
        { quantity: '12', net: '1200.000', tax: '242.280', total: '1442.280' },
      ],
    });
    expect(await counted).toEqual([
      { quantity: '1', net: '100.000', tax: '20.190', total: '120.190' },
      { quantity: '12', net: '1200.000', tax: '242.280', total: '1442.280' },
    ]);

    const refused = api.pricePreview('c1', '100.000', ['nope'], ['1']);
    http
      .expectOne('/api/companies/c1/price-preview')
      .flush({}, { status: 422, statusText: 'Unprocessable Content' });
    await expect(refused).rejects.toBeInstanceOf(ProductsRefused);
  });
});
