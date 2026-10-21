// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { VendorsApi, VendorsRefused } from './vendors-api';
import type { VendorInput, VendorSearch } from './vendors-types';

const everyVendor: VendorSearch = {
  page: 1,
  itemsPerPage: 25,
  q: '',
  isActive: null,
  toCheck: null,
  intervals: {},
  order: null,
};

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
  defaultExpenseCategoryId: 'k2',
  toCheck: false,
  checkNote: null,
  nameDoubtful: false,
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

  it('names the file of what the list shows, with its words, choice and order and no page', () => {
    const search = {
      page: 2,
      itemsPerPage: 50,
      q: ' acier ',
      isActive: true,
      toCheck: null,
      intervals: { 'createdAt.from': '2026-01-01' },
      order: { key: 'city' as const, direction: 'desc' as const },
    };

    expect(api.exportUrl('c/1', search, 'csv')).toBe(
      '/api/companies/c%2F1/exports/vendors.csv?q=acier&isActive=true&createdAt%5Bfrom%5D=2026-01-01&order%5Bcity%5D=desc',
    );
  });

  it('reads a vendor, gathering its address', async () => {
    const pending = api.vendor('c1', 'v 1');
    http.expectOne('/api/companies/c1/vendors/v%201').flush({
      id: 'v 1',
      version: 4,
      number: 'FRN-0001',
      name: 'Sotumag',
      identifiers: {},
      addressLine1: 'Zone industrielle',
      city: 'Ben Arous',
      countryCode: 'TN',
      iban: 'TN5910006035183598478831',
      paymentTermsDays: 30,
      isActive: true,
      defaultExpenseCategoryId: 'k2',
    });

    expect(await pending).toEqual({
      ...sotumag,
      id: 'v 1',
      version: 4,
      mergedInto: null,
      checkReasons: [],
    });
  });

  it('reads « À vérifier » on a vendor, leaving out a reason this screen does not know', async () => {
    const pending = api.vendor('c1', 'v1');
    http.expectOne('/api/companies/c1/vendors/v1').flush({
      id: 'v1',
      version: 1,
      number: 'FRN-0001',
      name: 'Sotumag',
      toCheck: true,
      checkNote: 'RIB à confirmer',
      nameDoubtful: true,
      checkReasons: ['later_reason', 'name_doubtful'],
    });

    expect(await pending).toMatchObject({
      toCheck: true,
      checkNote: 'RIB à confirmer',
      nameDoubtful: true,
      checkReasons: ['name_doubtful'],
    });
  });

  it('narrows the list to the vendors to check, or to the others', async () => {
    for (const [toCheck, query] of [
      [true, 'toCheck=true'],
      [false, 'toCheck=false'],
    ] as const) {
      const pending = api.vendors('c1', { ...everyVendor, toCheck });
      const request = http.expectOne((req) => req.url === '/api/companies/c1/vendors');
      expect(request.request.params.toString()).toBe(`page=1&itemsPerPage=25&${query}`);
      request.flush({ member: [], totalItems: 0 });
      await pending;
    }
  });

  it('reads the vendor a vendor merged away points at', async () => {
    const kept = { id: 'v1', number: 'FRN-0001', name: 'Sotumag' };
    const pending = api.vendor('c1', 'v2');
    http
      .expectOne('/api/companies/c1/vendors/v2')
      .flush({ id: 'v2', version: 2, number: 'FRN-0002', name: 'Sotumag Sfax', mergedInto: kept });

    expect((await pending).mergedInto).toEqual(kept);
  });

  it('reads one page of vendors as the API searched, narrowed and sorted it, with the total', async () => {
    const pending = api.vendors('c1', {
      page: 3,
      itemsPerPage: 50,
      q: ' générale ',
      isActive: true,
      toCheck: null,
      intervals: { 'paymentTermsDays.min': '30', 'paymentTermsDays.max': '60' },
      order: { key: 'paymentTermsDays', direction: 'desc' },
    });
    const request = http.expectOne((req) => req.url === '/api/companies/c1/vendors');
    expect(request.request.headers.get('Accept')).toBe('application/ld+json');
    expect(request.request.params.toString()).toBe(
      'page=3&itemsPerPage=50&q=g%C3%A9n%C3%A9rale&isActive=true&paymentTermsDays%5Bmin%5D=30&paymentTermsDays%5Bmax%5D=60&order%5BpaymentTermsDays%5D=desc',
    );
    request.flush({ member: [{ id: 'v1', number: 'FRN-0001', name: 'Sotumag' }], totalItems: 101 });

    const page = await pending;
    expect(page.total).toBe(101);
    expect(page.rows.map((row) => [row.id, row.name])).toEqual([['v1', 'Sotumag']]);
  });

  it('leaves out of the query what the list does not narrow by', async () => {
    const pending = api.vendors('c1', everyVendor);
    const request = http.expectOne((req) => req.url === '/api/companies/c1/vendors');
    expect(request.request.params.toString()).toBe('page=1&itemsPerPage=25');
    request.flush({ member: [], totalItems: 0 });

    await expect(pending).resolves.toEqual({ rows: [], total: 0 });
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
      defaultExpenseCategoryId: 'k2',
    });
    expect(request.request.body).not.toHaveProperty('address');
    request.flush({ ...sotumag, id: 'v1' });
    await pending;
  });

  it('says a number another vendor has, and a field the API refused', async () => {
    const taken = api.reviseVendor('c1', 'v1', sotumag, 1);
    http
      .expectOne('/api/companies/c1/vendors/v1')
      .flush(null, { status: 409, statusText: 'Conflict' });
    await expect(taken).rejects.toEqual(new VendorsRefused('number_taken'));

    const refused = api.createVendor('c1', sotumag);
    http
      .expectOne('/api/companies/c1/vendors')
      .flush(null, { status: 422, statusText: 'Unprocessable Content' });
    await expect(refused).rejects.toEqual(new VendorsRefused('invalid'));

    const named = api.createVendor('c1', sotumag);
    http.expectOne('/api/companies/c1/vendors').flush(
      {
        violations: [{ propertyPath: 'iban', message: 'x', payload: { reason: 'invalid_iban' } }],
      },
      { status: 422, statusText: 'Unprocessable Content' },
    );
    await expect(named).rejects.toMatchObject({
      code: 'invalid',
      refusals: [{ field: 'iban', reason: 'invalid_iban', params: {} }],
    });
  });

  it("reads the form's options", async () => {
    const pending = api.options('c1');
    http.expectOne('/api/companies/c1/vendor-options').flush({
      countryCode: 'TN',
      identifiers: [{ key: 'matricule_fiscal', label: 'Matricule fiscal', pattern: '^x$' }],
      expenseCategories: [{ id: 'k1', name: 'Fournitures', active: true }],
    });

    expect(await pending).toEqual({
      countryCode: 'TN',
      identifiers: [{ key: 'matricule_fiscal', label: 'Matricule fiscal', pattern: '^x$' }],
      expenseCategories: [{ id: 'k1', name: 'Fournitures', active: true }],
    });

    // The expenses module off: no category is offered, and the form has no such field.
    const off = api.options('c1');
    http
      .expectOne('/api/companies/c1/vendor-options')
      .flush({ countryCode: 'TN', identifiers: [] });
    expect((await off).expenseCategories).toBeNull();
  });

  it('sends back the version revised, and tells a version saved since from a number taken', async () => {
    const changed = api.reviseVendor('c1', 'v1', sotumag, 3);
    const request = http.expectOne('/api/companies/c1/vendors/v1');
    expect(request.request.body).toMatchObject({ version: 3, number: sotumag.number });
    request.flush(
      { type: '/problems/record-changed', status: 409 },
      { status: 409, statusText: 'Conflict' },
    );
    await expect(changed).rejects.toMatchObject({ code: 'record_changed' });

    const taken = api.reviseVendor('c1', 'v1', sotumag, 3);
    http
      .expectOne('/api/companies/c1/vendors/v1')
      .flush({ type: '/errors/409' }, { status: 409, statusText: 'Conflict' });
    await expect(taken).rejects.toMatchObject({ code: 'number_taken' });
  });

  // docs/SPEC.md § 7, the step-up list: API Platform leads the 403 with the token.
  it('names the proof the API waits for before it keeps new bank details', async () => {
    const waiting = api.reviseVendor('c1', 'v1', sotumag, 1);
    http
      .expectOne('/api/companies/c1/vendors/v1')
      .flush(
        { detail: 'step_up_required: confirm who you are first.' },
        { status: 403, statusText: 'Forbidden' },
      );
    await expect(waiting).rejects.toEqual(new VendorsRefused('step_up_required'));
  });
});
