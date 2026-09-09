// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { CSRF_HEADER, csrfInterceptor, csrfToken } from './csrf-interceptor';

describe('csrfInterceptor', () => {
  let http: HttpClient;
  let backend: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([csrfInterceptor])),
        provideHttpClientTesting(),
      ],
    });
    http = TestBed.inject(HttpClient);
    backend = TestBed.inject(HttpTestingController);
  });

  afterEach(() => backend.verify());

  it('adds the same random header to every API request, 24 characters or more', () => {
    http.get('/api/auth/me').subscribe();
    http.post('/api/auth/logout', null).subscribe();
    const [first, second] = backend.match(() => true);

    const value = first.request.headers.get(CSRF_HEADER);
    expect(value).toMatch(/^[0-9a-f]{32}$/);
    expect(value!.length).toBeGreaterThanOrEqual(24);
    expect(second.request.headers.get(CSRF_HEADER)).toBe(value);
    expect(csrfToken()).toBe(value);
    first.flush({});
    second.flush(null);
  });

  it('leaves requests outside the API alone', () => {
    http.get('/i18n/fr.json').subscribe();
    const request = backend.expectOne('/i18n/fr.json');
    expect(request.request.headers.has(CSRF_HEADER)).toBe(false);
    request.flush({});
  });
});
