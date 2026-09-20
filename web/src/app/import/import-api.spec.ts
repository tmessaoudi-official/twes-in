// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ImportApi, ImportNotKept, ImportRefused } from './import-api';

describe('ImportApi', () => {
  let api: ImportApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(ImportApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('reads the guide for one subject of one company', async () => {
    const pending = api.guide('c1', 'opening-stock');
    http.expectOne('/api/companies/c1/imports/opening-stock').flush({
      subject: 'opening-stock',
      identity: ['reference', 'location_code'],
      maxRows: 2000,
      columns: [
        {
          key: 'reference',
          required: true,
          headingKey: 'import.opening_stock.reference',
          label: null,
          example: 'VIS-6X40',
          noteKey: null,
        },
      ],
    });

    const guide = await pending;
    expect(guide.identity).toEqual(['reference', 'location_code']);
    expect(guide.maxRows).toBe(2000);
    expect(guide.columns[0].key).toBe('reference');
  });

  it('sends the file as one multipart part named file, beside the mode and the preview flag', async () => {
    const file = new File(['reference,quantity\n'], 'stocks.csv', { type: 'text/csv' });
    const pending = api.run('c1', 'opening-stock', file, 'upsert', true);

    const request = http.expectOne('/api/companies/c1/imports/opening-stock');
    expect(request.request.body).toBeInstanceOf(FormData);
    const body = request.request.body as FormData;
    expect((body.get('file') as File).name).toBe('stocks.csv');
    expect(body.get('mode')).toBe('upsert');
    expect(body.get('dryRun')).toBe('1');

    request.flush({ committed: false, created: [2], updated: [], rejected: [] });
    const report = await pending;
    expect(report).toEqual({ committed: false, created: [2], updated: [], rejected: [] });
  });

  it('reads a 422 carrying a report as the answer, not as an error', async () => {
    const file = new File(['x'], 'stocks.csv');
    const pending = api.run('c1', 'customers', file, 'create', false);
    http.expectOne('/api/companies/c1/imports/customers').flush(
      {
        committed: false,
        created: [],
        updated: [],
        rejected: [
          { line: 3, column: 'number', code: 'already_exists', params: {}, message: 'Already.' },
        ],
      },
      { status: 422, statusText: 'Unprocessable Content' },
    );

    const refused = await pending.catch((error: unknown) => error);
    expect(refused).toBeInstanceOf(ImportNotKept);
    const notKept = refused as ImportNotKept;
    expect(notKept.refusal).toBeNull();
    expect(notKept.report?.rejected[0].code).toBe('already_exists');
  });

  it('reads a 422 refusing the file whole, with the columns and the cap it names', async () => {
    const file = new File(['x'], 'stocks.csv');
    const pending = api.run('c1', 'customers', file, 'create', false);
    http
      .expectOne('/api/companies/c1/imports/customers')
      .flush(
        { error: 'unknown_columns', columns: ['nom', 'prenom'], limit: null },
        { status: 422, statusText: 'Unprocessable Content' },
      );

    const refused = (await pending.catch((error: unknown) => error)) as ImportNotKept;
    expect(refused).toBeInstanceOf(ImportNotKept);
    expect(refused.report).toBeNull();
    expect(refused.refusal).toEqual({
      reason: 'unknown_columns',
      columns: ['nom', 'prenom'],
      limit: null,
    });
  });

  it('turns a 404 into not_found and an unreachable API into network', async () => {
    const missing = api.guide('c1', 'nothing');
    http
      .expectOne('/api/companies/c1/imports/nothing')
      .flush(null, { status: 404, statusText: 'Not Found' });
    expect(((await missing.catch((e: unknown) => e)) as ImportRefused).code).toBe('not_found');

    const offline = api.guide('c1', 'customers');
    http.expectOne('/api/companies/c1/imports/customers').error(new ProgressEvent('error'));
    expect(((await offline.catch((e: unknown) => e)) as ImportRefused).code).toBe('network');
  });

  it('opens the empty file at a same-origin address the session cookie reaches', () => {
    expect(api.templateUrl('c1', 'opening-stock', 'xlsx')).toBe(
      '/api/companies/c1/import-templates/opening-stock.xlsx',
    );
  });
});
