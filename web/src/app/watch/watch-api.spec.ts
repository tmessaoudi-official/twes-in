// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { WatchApi } from './watch-api';
import { WatchSubjectGone } from './watch-types';

const KIND = 'invoices.late_customer';
const ROWS = `/api/companies/k1/watch/${KIND}`;

describe('WatchApi', () => {
  let api: WatchApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(WatchApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('reads the whole count and one count per subject, and no row', async () => {
    const result = api.summary('k1');
    http
      .expectOne('/api/companies/k1/watch')
      .flush({ count: 5, subjects: [{ kind: KIND, count: 5 }] });

    expect(await result).toEqual({ count: 5, subjects: [{ kind: KIND, count: 5 }] });
  });

  it('asks a subject for its page, numbered from 1 on the wire and from 0 for the caller', async () => {
    const result = api.rows('k1', KIND, 2, 50);
    const request = http.expectOne((r) => r.url === ROWS);
    expect(request.request.params.get('page')).toBe('3');
    expect(request.request.params.get('itemsPerPage')).toBe('50');
    expect(request.request.headers.get('Accept')).toBe('application/ld+json');
    request.flush({
      totalItems: 212,
      member: [{ kind: KIND, subjectId: 'c1', params: { customer: 'Carthage' } }],
    });

    expect(await result).toEqual({
      total: 212,
      rows: [{ kind: KIND, subjectId: 'c1', params: { customer: 'Carthage' } }],
    });
  });

  it('refuses a page that comes without its total, because the paginator would lie', async () => {
    const result = api.rows('k1', KIND, 0, 25);
    http.expectOne((r) => r.url === ROWS).flush({ member: [] });

    await expect(result).rejects.toThrow('without its total');
  });

  it('turns the 404 of a subject nobody may see into WatchSubjectGone, and keeps any other failure as it is', async () => {
    const gone = api.rows('k1', KIND, 0, 25);
    http.expectOne((r) => r.url === ROWS).flush('', { status: 404, statusText: 'Not Found' });
    await expect(gone).rejects.toBeInstanceOf(WatchSubjectGone);

    const broken = api.rows('k1', KIND, 0, 25);
    http.expectOne((r) => r.url === ROWS).flush('', { status: 500, statusText: 'Server Error' });
    await expect(broken).rejects.not.toBeInstanceOf(WatchSubjectGone);
  });
});
