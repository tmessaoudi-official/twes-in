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
  kind: null,
  isActive: null,
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
    });

    expect(await pending).toEqual({
      currency: 'TND',
      currencyScale: 3,
      units: [{ id: 'u1', code: 'C62', name: 'Unité', decimals: 0 }],
      taxes: [{ id: 't1', code: 'TVA19', name: 'TVA 19 %', family: 'vat' }],
    });
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
    });
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
      kind: 'goods',
      isActive: false,
      order: { key: 'category', direction: 'desc' },
    });
    const request = http.expectOne((req) => req.url === '/api/companies/c1/products');
    expect(request.request.headers.get('Accept')).toBe('application/ld+json');
    expect(request.request.params.toString()).toBe(
      'page=2&itemsPerPage=50&q=%C3%A9cran&kind=goods&isActive=false&order%5Bcategory%5D=desc',
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
});
