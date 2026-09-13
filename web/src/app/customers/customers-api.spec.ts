// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { CustomersApi } from './customers-api';
import type { CustomerInput } from './customers-types';

const carthage: CustomerInput = {
  number: 'CLI-0001',
  kind: 'company',
  customerGroupId: null,
  taxRegime: 'standard',
  name: 'Carthage Conseil',
  legalName: null,
  identifiers: { matricule_fiscal: '1234567A/B/M/000' },
  email: null,
  phone: null,
  website: null,
  billingAddress: {
    line1: '12, rue du Lac',
    line2: null,
    postalCode: null,
    city: 'Tunis',
    countryCode: 'TN',
  },
  shippingAddress: null,
  defaultTaxComponentIds: ['t1'],
  defaultDiscountRate: null,
  notes: null,
  isActive: true,
  customFields: {},
};

describe('CustomersApi', () => {
  let api: CustomersApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(CustomersApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('reads a customer, gathering each address and leaving no shipping address when it has none', async () => {
    const pending = api.customer('c1', 'k 1');
    http.expectOne('/api/companies/c1/customers/k%201').flush({
      id: 'k 1',
      number: 'CLI-0001',
      kind: 'company',
      taxRegime: 'standard',
      name: 'Carthage Conseil',
      identifiers: { matricule_fiscal: '1234567A/B/M/000' },
      billingAddressLine1: '12, rue du Lac',
      billingCity: 'Tunis',
      billingCountryCode: 'TN',
      defaultTaxComponentIds: ['t1'],
      isActive: true,
    });

    expect(await pending).toEqual({ ...carthage, id: 'k 1' });
  });

  it('sends a customer with flat address fields', async () => {
    const pending = api.createCustomer('c1', {
      ...carthage,
      shippingAddress: {
        line1: null,
        line2: null,
        postalCode: null,
        city: 'Sfax',
        countryCode: 'TN',
      },
      customFields: { sector: 'retail', vip: true },
    });
    const request = http.expectOne('/api/companies/c1/customers');
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toMatchObject({
      number: 'CLI-0001',
      billingAddressLine1: '12, rue du Lac',
      billingCity: 'Tunis',
      shippingCity: 'Sfax',
      shippingAddressLine1: null,
      customFields: { sector: 'retail', vip: true },
    });
    expect(request.request.body).not.toHaveProperty('billingAddress');
    request.flush({ id: 'k1', ...request.request.body });

    expect((await pending).shippingAddress?.city).toBe('Sfax');
  });

  it('names a number another customer has, and a customer refused for what it says', async () => {
    const taken = api.createCustomer('c1', carthage);
    http
      .expectOne('/api/companies/c1/customers')
      .flush({}, { status: 409, statusText: 'Conflict' });
    await expect(taken).rejects.toMatchObject({ code: 'number_taken' });

    const refused = api.reviseCustomer('c1', 'k1', carthage);
    const request = http.expectOne('/api/companies/c1/customers/k1');
    expect(request.request.method).toBe('PUT');
    request.flush({ detail: 'taxRegime: no' }, { status: 422, statusText: 'Unprocessable' });
    await expect(refused).rejects.toMatchObject({ code: 'invalid' });
  });

  it('reads what the form offers', async () => {
    const pending = api.options('c1');
    http.expectOne('/api/companies/c1/customer-options').flush({
      countryCode: 'TN',
      identifiers: [
        {
          key: 'matricule_fiscal',
          label: 'Matricule fiscal',
          pattern: '^x$',
          requiredForBusiness: true,
        },
      ],
      regimes: [{ code: 'exempt', label: 'Exonéré', excludedFamilies: ['vat'] }],
      taxes: [{ id: 't1', code: 'TVA19', name: 'TVA 19 %', family: 'vat' }],
    });

    const options = await pending;
    expect(options.identifiers[0]?.requiredForBusiness).toBe(true);
    expect(options.regimes[0]?.excludedFamilies).toEqual(['vat']);
    expect(options.taxes.map((tax) => tax.code)).toEqual(['TVA19']);
  });

  it('names a group name already taken, and a group still in use', async () => {
    const taken = api.createGroup('c1', { name: 'Grossistes', description: null });
    http
      .expectOne('/api/companies/c1/customer-groups')
      .flush({}, { status: 409, statusText: 'Conflict' });
    await expect(taken).rejects.toMatchObject({ code: 'name_taken' });

    const inUse = api.deleteGroup('c1', 'g1');
    const request = http.expectOne('/api/companies/c1/customer-groups/g1');
    expect(request.request.method).toBe('DELETE');
    request.flush({}, { status: 409, statusText: 'Conflict' });
    await expect(inUse).rejects.toMatchObject({ code: 'in_use' });
  });

  it('adds a contact under its customer and removes one', async () => {
    const contact = {
      firstName: 'Leila',
      lastName: null,
      email: null,
      phone: null,
      role: null,
      isPrimary: true,
    };
    const added = api.addContact('c1', 'k1', contact);
    const post = http.expectOne('/api/companies/c1/customers/k1/contacts');
    expect(post.request.body).toEqual(contact);
    post.flush({ id: 'p1', ...contact });
    expect((await added).id).toBe('p1');

    const removed = api.removeContact('c1', 'k1', 'p1');
    const request = http.expectOne('/api/companies/c1/customers/k1/contacts/p1');
    expect(request.request.method).toBe('DELETE');
    request.flush(null, { status: 204, statusText: 'No Content' });
    await expect(removed).resolves.toBeUndefined();
  });

  it('says the server could not be reached', async () => {
    const pending = api.customers('c1');
    http.expectOne('/api/companies/c1/customers').error(new ProgressEvent('error'));

    await expect(pending).rejects.toMatchObject({ code: 'network' });
  });
});
