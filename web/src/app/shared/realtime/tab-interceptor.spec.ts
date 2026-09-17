// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { TAB_HEADER, tabId, tabInterceptor } from './tab-interceptor';

describe('tabInterceptor', () => {
  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([tabInterceptor])),
        provideHttpClientTesting(),
      ],
    });
  });

  it('names this tab on every API request, the same name for the whole page load', () => {
    const http = TestBed.inject(HttpClient);
    const backend = TestBed.inject(HttpTestingController);

    http.get('/api/customers').subscribe();
    http.post('/api/vendors', {}).subscribe();

    const names = [backend.expectOne('/api/customers'), backend.expectOne('/api/vendors')].map(
      (request) => request.request.headers.get(TAB_HEADER),
    );
    expect(names).toEqual([tabId(), tabId()]);
    expect(tabId()).toMatch(/^[A-Za-z0-9-]{1,64}$/);
  });

  it('names it to nobody else', () => {
    const http = TestBed.inject(HttpClient);
    http.get('/i18n/fr.json').subscribe();

    expect(
      TestBed.inject(HttpTestingController)
        .expectOne('/i18n/fr.json')
        .request.headers.has(TAB_HEADER),
    ).toBe(false);
  });
});
