// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ProductPhotosApi, ProductPhotosRefused } from './product-photos-api';

describe('ProductPhotosApi', () => {
  let api: ProductPhotosApi;
  let http: HttpTestingController;
  const base = '/api/companies/c%201/products/p1/photos';

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(ProductPhotosApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('reads the gallery in its order', async () => {
    const pending = api.list('c 1', 'p1');
    http.expectOne(base).flush([
      {
        id: 'a',
        name: 'a.jpg',
        mime: 'image/jpeg',
        size: 10,
        width: 4,
        height: 3,
        main: true,
        createdAt: 't',
      },
      { id: 'b' },
    ]);

    expect(await pending).toEqual([
      {
        id: 'a',
        name: 'a.jpg',
        mime: 'image/jpeg',
        size: 10,
        width: 4,
        height: 3,
        main: true,
        createdAt: 't',
      },
      { id: 'b', name: '', mime: '', size: 0, width: 0, height: 0, main: false, createdAt: '' },
    ]);
  });

  it('sends a photo as a multipart part named file', async () => {
    const file = new File(['bytes'], 'perceuse.jpg', { type: 'image/jpeg' });
    const pending = api.add('c 1', 'p1', file);
    const request = http.expectOne({ method: 'POST', url: base });
    expect((request.request.body as FormData).get('file')).toBeInstanceOf(File);
    request.flush({ id: 'a', main: true });

    expect((await pending).id).toBe('a');
  });

  it('sends the whole order, marks main, removes and puts back', async () => {
    const order = api.order('c 1', 'p1', ['b', 'a']);
    const ordered = http.expectOne({ method: 'POST', url: `${base}/order` });
    expect(ordered.request.body).toEqual({ photoIds: ['b', 'a'] });
    ordered.flush(null);
    await order;

    const main = api.markMain('c 1', 'p1', 'b');
    http.expectOne({ method: 'POST', url: `${base}/b/main` }).flush(null);
    await main;
    const removed = api.remove('c 1', 'p1', 'b');
    http.expectOne({ method: 'DELETE', url: `${base}/b` }).flush(null);
    await removed;
    const restored = api.restore('c 1', 'p1', 'b');
    http.expectOne({ method: 'POST', url: `${base}/b/restore` }).flush(null);
    await restored;
  });

  it('names a picture by its size', () => {
    expect(api.url('c 1', 'p1', 'a', 'small')).toBe(`${base}/a/content?size=small`);
  });

  it('carries the API’s refusal code and its numbers, the size said in megabytes', async () => {
    const pending = api.add('c 1', 'p1', new File(['x'], 'x.jpg'));
    http.expectOne(base).flush(
      {
        code: 'too_large',
        params: { maxBytes: 5242880 },
        message: 'A photo is at most 5120 KB.',
      },
      { status: 422, statusText: 'Unprocessable' },
    );

    await expect(pending).rejects.toEqual(
      new ProductPhotosRefused({ code: 'too_large', params: { maxBytes: 5242880, megabytes: 5 } }),
    );
  });

  it('says a gallery changed meanwhile on 409, and a lost photo on 404', async () => {
    const order = api.order('c 1', 'p1', ['a']);
    http.expectOne(`${base}/order`).flush({}, { status: 409, statusText: 'Conflict' });
    await expect(order).rejects.toMatchObject({ refusal: { code: 'changed' } });

    const main = api.markMain('c 1', 'p1', 'x');
    http.expectOne(`${base}/x/main`).flush({}, { status: 404, statusText: 'Not Found' });
    await expect(main).rejects.toMatchObject({ refusal: { code: 'not_found' } });
  });
});
