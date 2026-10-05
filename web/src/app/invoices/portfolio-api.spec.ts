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
      status: 'open',
      order: { key: 'dueOn', direction: 'desc' },
    });
    const request = http.expectOne((r) => r.url === '/api/companies/c%2F1/instruments');
    expect(request.request.headers.get('Accept')).toBe('application/ld+json');
    expect(request.request.params.get('page')).toBe('2');
    expect(request.request.params.get('itemsPerPage')).toBe('50');
    expect(request.request.params.get('status')).toBe('open');
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
    const page = api.portfolio('c1', { page: 1, itemsPerPage: 25, status: null, order: null });
    const request = http.expectOne((r) => r.url === '/api/companies/c1/instruments');
    expect(request.request.params.has('status')).toBe(false);
    expect(request.request.params.keys().some((key) => key.startsWith('order'))).toBe(false);
    request.flush({ member: [], totalItems: 0 });
    expect((await page).rows).toEqual([]);
  });

  it('refuses a page that came without its total, and says a refusal with the code the screen translates', async () => {
    const unplaced = api.portfolio('c1', { page: 1, itemsPerPage: 25, status: null, order: null });
    http.expectOne((r) => r.url === '/api/companies/c1/instruments').flush({ member: [] });
    await expect(unplaced).rejects.toBeInstanceOf(InvoicesRefused);

    const gone = api.portfolio('c1', { page: 1, itemsPerPage: 25, status: null, order: null });
    http
      .expectOne((r) => r.url === '/api/companies/c1/instruments')
      .flush({}, { status: 404, statusText: 'Not Found' });
    await expect(gone).rejects.toMatchObject({ code: 'not_found' });
  });
});
