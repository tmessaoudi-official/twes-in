// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { CompanyApi, CompanyRefused } from './company-api';
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
