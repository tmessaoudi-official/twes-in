// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { VendorsApi, VendorsRefused } from './vendors-api';
import type { VendorInput } from './vendors-types';

const sotumag: VendorInput = {
  number: 'FRN-0001',
  name: 'Sotumag',
  legalName: null,
  identifiers: {},
  email: null,
  phone: null,
  website: null,
  address: {
    line1: 'Zone industrielle',
    line2: null,
    postalCode: null,
    city: 'Ben Arous',
    countryCode: 'TN',
  },
  iban: 'TN5910006035183598478831',
  bic: null,
  paymentTermsDays: 30,
  notes: null,
  isActive: true,
};

describe('VendorsApi', () => {
  let api: VendorsApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(VendorsApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('reads a vendor, gathering its address', async () => {
    const pending = api.vendor('c1', 'v 1');
    http.expectOne('/api/companies/c1/vendors/v%201').flush({
      id: 'v 1',
      number: 'FRN-0001',
      name: 'Sotumag',
      identifiers: {},
      addressLine1: 'Zone industrielle',
      city: 'Ben Arous',
      countryCode: 'TN',
      iban: 'TN5910006035183598478831',
      paymentTermsDays: 30,
      isActive: true,
    });

    expect(await pending).toEqual({ ...sotumag, id: 'v 1' });
  });

  it('sends a vendor with flat address fields', async () => {
    const pending = api.createVendor('c1', sotumag);
    const request = http.expectOne('/api/companies/c1/vendors');
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toMatchObject({
      number: 'FRN-0001',
      addressLine1: 'Zone industrielle',
      city: 'Ben Arous',
      countryCode: 'TN',
      paymentTermsDays: 30,
    });
    expect(request.request.body).not.toHaveProperty('address');
    request.flush({ ...sotumag, id: 'v1' });
    await pending;
  });

  it('says a number another vendor has, and a field the API refused', async () => {
    const taken = api.reviseVendor('c1', 'v1', sotumag);
    http
      .expectOne('/api/companies/c1/vendors/v1')
      .flush(null, { status: 409, statusText: 'Conflict' });
    await expect(taken).rejects.toEqual(new VendorsRefused('number_taken'));

    const refused = api.createVendor('c1', sotumag);
    http
      .expectOne('/api/companies/c1/vendors')
      .flush(null, { status: 422, statusText: 'Unprocessable Content' });
    await expect(refused).rejects.toEqual(new VendorsRefused('invalid'));
  });

  it("reads the form's options", async () => {
    const pending = api.options('c1');
    http.expectOne('/api/companies/c1/vendor-options').flush({
      countryCode: 'TN',
      identifiers: [{ key: 'matricule_fiscal', label: 'Matricule fiscal', pattern: '^x$' }],
    });

    expect(await pending).toEqual({
      countryCode: 'TN',
      identifiers: [{ key: 'matricule_fiscal', label: 'Matricule fiscal', pattern: '^x$' }],
    });
  });
});
