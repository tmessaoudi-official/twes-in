// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ClosingApi, ClosingRefused } from './closing-api';

describe('ClosingApi', () => {
  let api: ClosingApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(ClosingApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('reads nothing closed as null, and closes through the day sent', async () => {
    const read = api.read('c/1');
    http.expectOne('/api/companies/c%2F1/closing').flush({ writable: true });
    expect(await read).toEqual({ closedThrough: null, writable: true });

    const closed = api.closeThrough('c1', '2026-09-30');
    const request = http.expectOne('/api/companies/c1/closing');
    expect([request.request.method, request.request.body]).toEqual([
      'PUT',
      { closedThrough: '2026-09-30' },
    ]);
    request.flush({ closedThrough: '2026-09-30', writable: true });
    expect(await closed).toEqual({ closedThrough: '2026-09-30', writable: true });
  });

  it('says a refused day as invalid, which the page explains', async () => {
    const closed = api.closeThrough('c1', '2026-08-31');
    http
      .expectOne('/api/companies/c1/closing')
      .flush({ detail: 'closedThrough: never opens again' }, { status: 422, statusText: 'x' });

    await expect(closed).rejects.toEqual(new ClosingRefused('invalid'));
  });
});
