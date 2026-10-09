// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { CompanyApi, CompanyRefused, companyLogoUrl } from './company-api';
import type { CompanyProfileChanges } from './company-types';

const changes: CompanyProfileChanges = {
  legalName: 'Demo SARL',
  legalForm: null,
  identifiers: { matricule_fiscal: '1234567' },
  addressLine1: null,
  addressLine2: null,
  postalCode: null,
  city: null,
  email: null,
  phone: null,
  website: null,
  iban: null,
  bic: null,
  vatRegime: 'standard',
  vatOnDebits: false,
  invoiceFooterText: null,
  latePenaltyText: null,
};

describe('CompanyApi, the profile', () => {
  let api: CompanyApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(CompanyApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('reads a profile whose absent values the API left out', async () => {
    const reading = api.profile('c1');
    http.expectOne({ method: 'GET', url: '/api/companies/c1/profile' }).flush({
      name: 'Demo',
      countryCode: 'TN',
      writable: true,
      identifiers: {},
      vatRegime: 'standard',
      identifierFields: [
        { key: 'matricule_fiscal', label: 'Matricule fiscal', pattern: '^x$', required: true },
      ],
      vatRegimes: [{ code: 'standard', label: 'Régime normal' }],
    });

    const profile = await reading;
    expect(profile.legalName).toBeNull();
    expect(profile.iban).toBeNull();
    expect(profile.identifiers).toEqual({});
    expect(profile.identifierFields[0]?.required).toBe(true);
    expect(profile.vatRegimes).toEqual([{ code: 'standard', label: 'Régime normal' }]);
    expect([profile.vatOnDebits, profile.offersVatOnDebits]).toEqual([false, false]);
  });

  it('names a value the preset refused as invalid, not as a member error', async () => {
    const revising = api.reviseProfile('c1', changes);
    const request = http.expectOne({ method: 'PUT', url: '/api/companies/c1/profile' });
    expect(request.request.body).toEqual(changes);
    request.flush(
      { detail: 'identifiers.matricule_fiscal: shape' },
      { status: 422, statusText: 'Unprocessable' },
    );

    await expect(revising).rejects.toEqual(new CompanyRefused('invalid'));
  });
});

describe('CompanyApi, the members', () => {
  let api: CompanyApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(CompanyApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('names a removal the actor role does not reach as forbidden, not as invalid', async () => {
    const removing = api.removeMember('c1', 'u2');
    http
      .expectOne({ method: 'DELETE', url: '/api/companies/c1/members/u2' })
      .flush(
        { detail: 'Your role in this company does not remove an owner.' },
        { status: 403, statusText: 'Forbidden' },
      );

    await expect(removing).rejects.toEqual(new CompanyRefused('forbidden'));
  });
});

// docs/SPEC.md § 7, 2026-09-25 09:03: « Société à l'ouverture », the company every sign-in opens.
describe('CompanyApi, the company a sign-in opens', () => {
  let api: CompanyApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(CompanyApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('says which company is pinned', async () => {
    const reading = api.companies();
    http.expectOne({ method: 'GET', url: '/api/me/companies' }).flush([
      { companyId: 'c1', name: 'Acme', status: 'active', role: 'owner', pinned: true },
      { companyId: 'c2', name: 'Globex', status: 'active', role: 'member' },
    ]);
    expect((await reading).map((company) => [company.id, company.pinned])).toEqual([
      ['c1', true],
      ['c2', false],
    ]);
  });

  it('pins a company, or none for the one last worked in', async () => {
    const pinning = api.pinAtSignIn('c2');
    const request = http.expectOne({ method: 'PUT', url: '/api/me/company-at-sign-in' });
    expect(request.request.body).toEqual({ companyId: 'c2' });
    request.flush({ companyId: 'c2' });
    await pinning;

    const unpinning = api.pinAtSignIn(null);
    const unpin = http.expectOne({ method: 'PUT', url: '/api/me/company-at-sign-in' });
    expect(unpin.request.body).toEqual({ companyId: null });
    unpin.flush({ companyId: null });
    await unpinning;
  });
});

describe('CompanyApi, the logo', () => {
  let api: CompanyApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(CompanyApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('sends the picture as a multipart part named file and answers the new version', async () => {
    const file = new File(['png'], 'logo.png', { type: 'image/png' });
    const sending = api.uploadLogo('c1', file);
    const request = http.expectOne({ method: 'POST', url: '/api/companies/c1/logo' });
    expect((request.request.body as FormData).get('file')).toBeInstanceOf(File);
    request.flush({ logoVersion: 'v2' }, { status: 201, statusText: 'Created' });

    expect(await sending).toBe('v2');
  });

  it("says a picture the API does not keep is refused, from its 422 or the proxy's 413", async () => {
    for (const status of [422, 413]) {
      const sending = api.uploadLogo('c1', new File(['x'], 'logo.png'));
      http
        .expectOne({ method: 'POST', url: '/api/companies/c1/logo' })
        .flush('', { status, statusText: 'Refused' });
      await expect(sending).rejects.toEqual(new CompanyRefused('logo_refused'));
    }
  });

  it('keeps the usual codes for the other failures', async () => {
    const missing = api.removeLogo('c1');
    http
      .expectOne({ method: 'DELETE', url: '/api/companies/c1/logo' })
      .flush('', { status: 404, statusText: 'Not Found' });
    await expect(missing).rejects.toEqual(new CompanyRefused('not_found'));

    const down = api.removeLogo('c1');
    http
      .expectOne({ method: 'DELETE', url: '/api/companies/c1/logo' })
      .error(new ProgressEvent('error'));
    await expect(down).rejects.toEqual(new CompanyRefused('network'));
  });

  it('names the logo by its version, so a changed one is a new address', () => {
    expect(companyLogoUrl('c 1', 'v/2')).toBe('/api/companies/c%201/logo?v=v%2F2');
  });

  it('reads the version the profile names, and none when the company has no logo', async () => {
    const reading = api.profile('c1');
    http
      .expectOne({ method: 'GET', url: '/api/companies/c1/profile' })
      .flush({ name: 'Demo', countryCode: 'TN', logoVersion: 'v1' });
    expect((await reading).logoVersion).toBe('v1');

    const without = api.profile('c1');
    http
      .expectOne({ method: 'GET', url: '/api/companies/c1/profile' })
      .flush({ name: 'Demo', countryCode: 'TN' });
    expect((await without).logoVersion).toBeNull();
  });
});
