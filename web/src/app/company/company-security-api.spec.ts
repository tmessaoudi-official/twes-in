// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { CompanySecurityApi, CompanySecurityRefused } from './company-security-api';

describe('CompanySecurityApi', () => {
  let api: CompanySecurityApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(CompanySecurityApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('reads whether the company requires a second factor, and whether the caller may change it', async () => {
    const pending = api.read('c1');
    http
      .expectOne({ method: 'GET', url: '/api/companies/c1/security' })
      .flush({ mfaRequired: true, writable: false });
    expect(await pending).toEqual({ mfaRequired: true, writable: false });
  });

  it('changes the requirement', async () => {
    const pending = api.requireSecondFactor('c1', true);
    const request = http.expectOne({ method: 'PUT', url: '/api/companies/c1/security' });
    expect(request.request.body).toEqual({ mfaRequired: true });
    request.flush({ mfaRequired: true, writable: true });
    expect(await pending).toEqual({ mfaRequired: true, writable: true });
  });

  it('turns a refusal into a code the page translates, and no answer at all into network', async () => {
    const refused = api.requireSecondFactor('c1', true);
    http
      .expectOne('/api/companies/c1/security')
      .flush({}, { status: 404, statusText: 'Not Found' });
    await expect(refused).rejects.toEqual(new CompanySecurityRefused('not_found'));

    const unreachable = api.read('c1');
    http.expectOne('/api/companies/c1/security').error(new ProgressEvent('error'));
    await expect(unreachable).rejects.toEqual(new CompanySecurityRefused('network'));
  });
});
