// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { DOCUMENT } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { languageInterceptor } from './language-interceptor';

describe('languageInterceptor', () => {
  let lang: string;

  beforeEach(() => {
    lang = document.documentElement.lang;
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([languageInterceptor])),
        provideHttpClientTesting(),
      ],
    });
  });

  afterEach(() => {
    document.documentElement.lang = lang;
  });

  it('asks the API for the language the screen shows, whatever the browser would send', () => {
    TestBed.inject(DOCUMENT).documentElement.lang = 'ar';
    const http = TestBed.inject(HttpClient);
    const backend = TestBed.inject(HttpTestingController);

    http.get('/api/companies/c1/customer-options').subscribe();
    expect(
      backend
        .expectOne('/api/companies/c1/customer-options')
        .request.headers.get('Accept-Language'),
    ).toBe('ar');

    TestBed.inject(DOCUMENT).documentElement.lang = 'en';
    http.get('/api/companies/c1/profile').subscribe();
    expect(
      backend.expectOne('/api/companies/c1/profile').request.headers.get('Accept-Language'),
    ).toBe('en');
  });

  it('leaves the texts files and a page with no language alone', () => {
    const http = TestBed.inject(HttpClient);
    const backend = TestBed.inject(HttpTestingController);
    TestBed.inject(DOCUMENT).documentElement.lang = 'fr';
    http.get('/i18n/fr.json').subscribe();
    expect(backend.expectOne('/i18n/fr.json').request.headers.has('Accept-Language')).toBe(false);

    TestBed.inject(DOCUMENT).documentElement.lang = '';
    http.get('/api/health').subscribe();
    expect(backend.expectOne('/api/health').request.headers.has('Accept-Language')).toBe(false);
  });
});
