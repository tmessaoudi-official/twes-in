// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ReportsApi } from './reports-api';
import { ReportGone, ReportRefused, type ReportQuery } from './reports-types';

const BASE = '/api/companies/k1/reports';
const SEPTEMBER: ReportQuery = {
  from: '2026-09-01',
  to: '2026-09-30',
  compare: 'previous',
  establishment: null,
};

describe('ReportsApi', () => {
  let api: ReportsApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(ReportsApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('reads the catalogue, and an answer naming no report as none', async () => {
    const columns = [{ key: 'gross', kind: 'amount', params: {} }];
    const offered = api.catalogue('k1');
    http.expectOne(BASE).flush({
      reports: [{ key: 'expenses-by-category', byEstablishment: false, periodic: true, columns }],
    });
    expect(await offered).toEqual([
      { key: 'expenses-by-category', byEstablishment: false, periodic: true, columns },
    ]);

    const none = api.catalogue('k1');
    http.expectOne(BASE).flush({});
    expect(await none).toEqual([]);
  });

  it('asks a report its period and comparison, and no comparison when there is none', async () => {
    const read = api.report('k1', 'takings', SEPTEMBER);
    const request = http.expectOne((r) => r.url === `${BASE}/takings`);
    expect(request.request.params.toString()).toBe(
      'from=2026-09-01&to=2026-09-30&compare=previous',
    );
    request.flush({
      key: 'takings',
      from: '2026-09-01',
      to: '2026-09-30',
      comparisonFrom: '2026-08-01',
      comparisonTo: '2026-08-31',
      columns: [],
      rows: [],
      totals: {},
      previousTotals: {},
    });
    expect((await read).comparisonFrom).toBe('2026-08-01');

    const today = api.report('k1', 'aged-balance', {
      from: null,
      to: null,
      compare: 'none',
      establishment: 'e1',
    });
    const asked = http.expectOne((r) => r.url === `${BASE}/aged-balance`);
    expect(asked.request.params.toString()).toBe('establishment=e1');
    asked.flush({
      key: 'aged-balance',
      from: '2026-10-18',
      to: '2026-10-18',
      comparisonFrom: null,
      comparisonTo: null,
      columns: [],
      rows: [],
      totals: {},
      previousTotals: null,
    });
    expect((await today).previousTotals).toBeNull();
  });

  it('turns 404 into ReportGone and 422 into ReportRefused, and keeps any other failure', async () => {
    const gone = api.report('k1', 'takings', SEPTEMBER);
    http
      .expectOne((r) => r.url === `${BASE}/takings`)
      .flush(null, { status: 404, statusText: 'Not Found' });
    await expect(gone).rejects.toBeInstanceOf(ReportGone);

    const refused = api.report('k1', 'takings', SEPTEMBER);
    http
      .expectOne((r) => r.url === `${BASE}/takings`)
      .flush(null, { status: 422, statusText: 'Unprocessable' });
    await expect(refused).rejects.toBeInstanceOf(ReportRefused);

    const failed = api.report('k1', 'takings', SEPTEMBER);
    http
      .expectOne((r) => r.url === `${BASE}/takings`)
      .flush(null, { status: 500, statusText: 'Error' });
    await expect(failed).rejects.not.toBeInstanceOf(ReportGone);
  });

  it('asks a row’s documents over the period alone, never the comparison', async () => {
    const read = api.documents('k1', 'takings', SEPTEMBER, 'd3');
    const request = http.expectOne((r) => r.url === `${BASE}/takings/documents`);
    expect(request.request.params.toString()).toBe('from=2026-09-01&to=2026-09-30&row=d3');
    const document = {
      kind: 'invoice',
      id: 'i1',
      number: 'F-1',
      party: 'Carthage',
      day: '2026-09-04',
      amount: '12.000',
    };
    request.flush({ documents: [document], more: true });
    expect(await read).toEqual({ documents: [document], more: true });
  });

  it('writes the file’s address with what the screen shows', () => {
    expect(api.exportAddress('k1', 'takings', SEPTEMBER, 'xlsx')).toBe(
      `${BASE}/takings/export.xlsx?from=2026-09-01&to=2026-09-30&compare=previous`,
    );
    expect(
      api.exportAddress(
        'k1',
        'dead-stock',
        { from: null, to: null, compare: 'none', establishment: null },
        'csv',
      ),
    ).toBe(`${BASE}/dead-stock/export.csv`);
  });
});
