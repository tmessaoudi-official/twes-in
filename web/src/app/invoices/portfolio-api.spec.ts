// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { InvoicesRefused } from './invoices-api';
import { PortfolioApi } from './portfolio-api';

const wire = {
  id: 'i1',
  invoiceId: 'f1',
  invoiceNumber: 'FAC-2026-10-00007',
  customerName: 'Carthage Conseil',
  currency: 'TND',
  kind: 'check',
  amount: '500.000',
  dueOn: '2026-11-15',
  bank: 'BT',
  number: 'CHQ-77',
  status: 'held',
  settledOn: null,
};

/** A search that narrows nothing and orders nothing, so a case names only what it changes. */
const EVERY = { status: [], kinds: [], customerIds: [], intervals: {}, order: null } as const;

describe('PortfolioApi', () => {
  let api: PortfolioApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(PortfolioApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('asks for the page, the status and the order the search names, and reads each row with its total', async () => {
    const page = api.portfolio('c/1', {
      page: 2,
      itemsPerPage: 50,
      status: ['open', 'cashed'],
      kinds: ['check', 'draft'],
      customerIds: ['k1', 'k2'],
      intervals: { 'dueOn.from': '2026-01-01', 'amount.max': '900.5' },
      order: { key: 'dueOn', direction: 'desc' },
    });
    const request = http.expectOne((r) => r.url === '/api/companies/c%2F1/instruments');
    expect(request.request.headers.get('Accept')).toBe('application/ld+json');
    expect(request.request.params.get('page')).toBe('2');
    expect(request.request.params.get('itemsPerPage')).toBe('50');
    // Repeated parameters, one per value, which the API ORs; the ends of an interval keep API Platform's bracket form.
    expect(request.request.params.getAll('status[]')).toEqual(['open', 'cashed']);
    expect(request.request.params.getAll('kind[]')).toEqual(['check', 'draft']);
    expect(request.request.params.getAll('customerId[]')).toEqual(['k1', 'k2']);
    expect(request.request.params.get('dueOn[from]')).toBe('2026-01-01');
    expect(request.request.params.get('amount[max]')).toBe('900.5');
    expect(request.request.params.get('order[dueOn]')).toBe('desc');
    request.flush({ member: [wire], totalItems: 61 });

    expect(await page).toEqual({
      total: 61,
      rows: [
        {
          id: 'i1',
          invoiceId: 'f1',
          invoiceNumber: 'FAC-2026-10-00007',
          customerName: 'Carthage Conseil',
          currency: 'TND',
          kind: 'check',
          amount: '500.000',
          dueOn: '2026-11-15',
          bank: 'BT',
          number: 'CHQ-77',
          status: 'held',
          settledOn: null,
        },
      ],
    });
  });

  it('sends no status and no order when the search names none', async () => {
    const page = api.portfolio('c1', { ...EVERY, page: 1, itemsPerPage: 25 });
    const request = http.expectOne((r) => r.url === '/api/companies/c1/instruments');
    expect(request.request.params.keys().sort()).toEqual(['itemsPerPage', 'page']);
    expect(request.request.params.keys().some((key) => key.startsWith('order'))).toBe(false);
    request.flush({ member: [], totalItems: 0 });
    expect((await page).rows).toEqual([]);
  });

  it('refuses a page that came without its total, and says a refusal with the code the screen translates', async () => {
    const unplaced = api.portfolio('c1', { ...EVERY, page: 1, itemsPerPage: 25 });
    http.expectOne((r) => r.url === '/api/companies/c1/instruments').flush({ member: [] });
    await expect(unplaced).rejects.toBeInstanceOf(InvoicesRefused);

    const gone = api.portfolio('c1', { ...EVERY, page: 1, itemsPerPage: 25 });
    http
      .expectOne((r) => r.url === '/api/companies/c1/instruments')
      .flush({}, { status: 404, statusText: 'Not Found' });
    await expect(gone).rejects.toMatchObject({ code: 'not_found' });
  });
});
