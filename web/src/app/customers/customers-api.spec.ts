// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { CustomersApi } from './customers-api';
import type { CustomerInput, CustomerSearch } from './customers-types';

const everyCustomer: CustomerSearch = {
  page: 1,
  itemsPerPage: 25,
  q: '',
  kinds: [],
  groupIds: [],
  regimes: [],
  isActive: null,
  intervals: {},
  order: null,
};

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

  it('names the file of what the list shows, with its words, choices and order and no page', () => {
    expect(
      api.exportUrl(
        'c/1',
        {
          ...everyCustomer,
          q: ' mer ',
          kinds: ['company'],
          isActive: false,
          intervals: { 'createdAt.to': '2026-03-31' },
          order: { key: 'name', direction: 'desc' },
        },
        'xlsx',
      ),
    ).toBe(
      '/api/companies/c%2F1/exports/customers.xlsx?q=mer&kind%5B%5D=company&isActive=false&createdAt%5Bto%5D=2026-03-31&order%5Bname%5D=desc',
    );
    expect(api.exportUrl('c1', everyCustomer, 'csv')).toBe(
      '/api/companies/c1/exports/customers.csv',
    );
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

  it('reads one page of customers as the API searched, narrowed and sorted it, with the total', async () => {
    const pending = api.customers('c1', {
      page: 2,
      itemsPerPage: 50,
      q: 'carthagé',
      kinds: ['company', 'individual'],
      groupIds: ['g1', 'g2'],
      regimes: ['exempt'],
      isActive: false,
      intervals: { 'createdAt.from': '2026-03-01' },
      order: { key: 'customerGroup', direction: 'desc' },
    });
    const request = http.expectOne((req) => req.url === '/api/companies/c1/customers');
    expect(request.request.headers.get('Accept')).toBe('application/ld+json');
    expect(request.request.params.toString()).toBe(
      'page=2&itemsPerPage=50&q=carthag%C3%A9&kind%5B%5D=company&kind%5B%5D=individual&customerGroupId%5B%5D=g1&customerGroupId%5B%5D=g2&taxRegime%5B%5D=exempt&isActive=false&createdAt%5Bfrom%5D=2026-03-01&order%5BcustomerGroup%5D=desc',
    );
    request.flush({ member: [{ id: 'k1', ...carthage }], totalItems: 51 });

    const page = await pending;
    expect(page.total).toBe(51);
    expect(page.rows.map((row) => row.name)).toEqual(['Carthage Conseil']);
  });

  it('leaves out of the query what the list does not narrow by', async () => {
    const pending = api.customers('c1', { ...everyCustomer });
    const request = http.expectOne((req) => req.url === '/api/companies/c1/customers');
    expect(request.request.params.toString()).toBe('page=1&itemsPerPage=25');
    request.flush({ member: [], totalItems: 0 });

    await expect(pending).resolves.toEqual({ rows: [], total: 0 });
  });

  it('says the server could not be reached', async () => {
    const pending = api.customers('c1', everyCustomer);
    http
      .expectOne((req) => req.url === '/api/companies/c1/customers')
      .error(new ProgressEvent('error'));

    await expect(pending).rejects.toMatchObject({ code: 'network' });
  });

  describe('depositCredit', () => {
    it('posts the money received to the customer’s credit balance', async () => {
      const deposit = { date: '2026-10-02', amount: '50', reference: 'VIR-9', notes: null };
      const pending = api.depositCredit('c 1', 'k1', deposit);
      const request = http.expectOne('/api/companies/c%201/customers/k1/credit-balance');
      expect(request.request.method).toBe('POST');
      expect(request.request.body).toEqual(deposit);
      request.flush({}, { status: 201, statusText: 'Created' });
      await pending;
    });
  });

  describe('statement', () => {
    const raw = {
      customerId: 'k1',
      customerName: 'Carthage Conseil',
      customerNumber: 'CLI-0001',
      currency: 'TND',
      currencyScale: 3,
      from: '2026-01-01',
      to: '2026-10-01',
      openingBalance: '70.000',
      totalDebit: '20.000',
      totalCredit: '0.000',
      closingBalance: '90.000',
      creditBalance: '300.000',
      lines: [
        {
          day: '2026-03-05',
          kind: 'invoice',
          number: 'FA-0002',
          documentId: 'i2',
          reference: null,
          debit: '20.000',
          credit: '0.000',
          balance: '90.000',
        },
      ],
    };

    it('asks for the period it is given and leaves out the one it is not', async () => {
      const pending = api.statement('c 1', 'k1', { from: '2026-02-01', to: '2026-12-31' });
      const request = http.expectOne(
        (req) => req.url === '/api/companies/c%201/customers/k1/statement',
      );
      expect(request.request.params.toString()).toBe('from=2026-02-01&to=2026-12-31');
      request.flush(raw);
      expect((await pending).openingBalance).toBe('70.000');

      const open = api.statement('c1', 'k1');
      const bare = http.expectOne((req) => req.url === '/api/companies/c1/customers/k1/statement');
      expect(bare.request.params.toString()).toBe('');
      bare.flush(raw);
      await open;

      const blank = api.statement('c1', 'k1', { from: '', to: '' });
      const empty = http.expectOne((req) => req.url === '/api/companies/c1/customers/k1/statement');
      expect(empty.request.params.toString()).toBe('');
      empty.flush(raw);
      await blank;
    });

    it('reads the account as the API worked it out, every amount kept as the string it came as', async () => {
      const pending = api.statement('c1', 'k1');
      http.expectOne((req) => req.url.endsWith('/statement')).flush(raw);

      const statement = await pending;
      expect(statement).toMatchObject({
        customerName: 'Carthage Conseil',
        currency: 'TND',
        currencyScale: 3,
        closingBalance: '90.000',
        creditBalance: '300.000',
      });
      expect(statement.lines).toEqual([
        {
          day: '2026-03-05',
          kind: 'invoice',
          number: 'FA-0002',
          documentId: 'i2',
          reference: null,
          debit: '20.000',
          credit: '0.000',
          balance: '90.000',
        },
      ]);
    });

    it('says a customer is not found, a period is refused and the server cannot be reached', async () => {
      for (const [status, code] of [
        [404, 'not_found'],
        [422, 'invalid'],
      ] as const) {
        const pending = api.statement('c1', 'k1');
        http
          .expectOne((req) => req.url.endsWith('/statement'))
          .flush({}, { status, statusText: 'refused' });
        await expect(pending).rejects.toMatchObject({ code });
      }
      const pending = api.statement('c1', 'k1');
      http.expectOne((req) => req.url.endsWith('/statement')).error(new ProgressEvent('error'));
      await expect(pending).rejects.toMatchObject({ code: 'network' });
    });
  });
});
