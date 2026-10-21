// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { LiveChanges } from './live-changes';
import { LIVE_CHANGES_HEADER, liveChangesInterceptor } from './live-changes-interceptor';

describe('liveChangesInterceptor', () => {
  const heardHere = vi.fn();

  beforeEach(() => {
    heardHere.mockClear();
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([liveChangesInterceptor])),
        provideHttpClientTesting(),
        { provide: LiveChanges, useValue: { heardHere } },
      ],
    });
  });

  it('hands this tab what the API says its request changed, before the caller reads the answer', () => {
    const http = TestBed.inject(HttpClient);
    const named = '[{"kind":"customer","id":"c1","action":"customer.revised"}]';
    let heardFirst = false;

    http.put('/api/companies/a/customers/c1', {}).subscribe(() => {
      heardFirst = heardHere.mock.calls.length === 1;
    });
    TestBed.inject(HttpTestingController)
      .expectOne('/api/companies/a/customers/c1')
      .flush({}, { headers: { [LIVE_CHANGES_HEADER]: named } });

    expect(heardHere).toHaveBeenCalledExactlyOnceWith(named);
    expect(heardFirst).toBe(true);
  });

  it('hands it what a refused request still changed, as one that failed after saving', () => {
    const http = TestBed.inject(HttpClient);
    const named = '[{"kind":"stock","id":"p1","action":"stock.received"}]';

    http.post('/api/companies/a/stock-movements', {}).subscribe({ error: () => undefined });
    TestBed.inject(HttpTestingController)
      .expectOne('/api/companies/a/stock-movements')
      .flush(
        { title: 'refused' },
        { status: 409, statusText: 'Conflict', headers: { [LIVE_CHANGES_HEADER]: named } },
      );

    expect(heardHere).toHaveBeenCalledExactlyOnceWith(named);
  });

  it('says nothing when the answer names no change', () => {
    const http = TestBed.inject(HttpClient);

    http.get('/api/companies/a/customers').subscribe();
    TestBed.inject(HttpTestingController).expectOne('/api/companies/a/customers').flush([]);

    expect(heardHere).not.toHaveBeenCalled();
  });
});
