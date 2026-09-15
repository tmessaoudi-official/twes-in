// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { SignupApi, SignupRefused } from './signup-api';
import type { SignupDetails } from './signup-types';

const details: SignupDetails = {
  displayName: 'Nadia',
  password: 'a-long-enough-password',
  companyName: 'Nouvelle Société',
  countryCode: 'TN',
  timezone: 'Africa/Tunis',
};

describe('SignupApi', () => {
  let api: SignupApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(SignupApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('reads whether signup is open and in which countries', async () => {
    const pending = api.availability();
    http
      .expectOne({ method: 'GET', url: '/api/signup' })
      .flush({ enabled: true, countries: ['FR', 'TN'] });

    expect(await pending).toEqual({ enabled: true, countries: ['FR', 'TN'] });
  });

  it('reads an answer that names nothing as closed', async () => {
    const pending = api.availability();
    http.expectOne({ method: 'GET', url: '/api/signup' }).flush({});

    expect(await pending).toEqual({ enabled: false, countries: [] });
  });

  it('asks for a link in the language the page speaks', async () => {
    const pending = api.request('new@example.test', 'en');
    const request = http.expectOne({ method: 'POST', url: '/api/signup' });
    expect(request.request.body).toEqual({ email: 'new@example.test', locale: 'en' });
    request.flush(null, { status: 202, statusText: 'Accepted' });

    await expect(pending).resolves.toBeUndefined();
  });

  it('describes a link, then finishes it', async () => {
    const described = api.link('a/token');
    http
      .expectOne({ method: 'GET', url: '/api/signup/a%2Ftoken' })
      .flush({ email: 'new@example.test' });
    expect(await described).toBe('new@example.test');

    const finished = api.complete('a/token', details);
    const request = http.expectOne({ method: 'POST', url: '/api/signup/a%2Ftoken/complete' });
    expect(request.request.body).toEqual(details);
    request.flush(
      { userId: '1', companyName: 'Nouvelle Société', companyStatus: 'pending' },
      { status: 201, statusText: 'Created' },
    );
    expect(await finished).toEqual({ companyName: 'Nouvelle Société', companyStatus: 'pending' });
  });

  it('names why a call was refused', async () => {
    const cases: [number, string][] = [
      [404, 'not_usable'],
      [422, 'refused'],
      [429, 'too_many'],
      [0, 'network'],
    ];
    for (const [status, code] of cases) {
      const pending = api.request('new@example.test', 'fr');
      http.expectOne('/api/signup').flush(null, { status, statusText: 'x' });
      await expect(pending).rejects.toEqual(new SignupRefused(code as SignupRefused['code']));
    }
  });
});
