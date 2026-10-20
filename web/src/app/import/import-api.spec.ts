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
      switches: [{ key: 'recount', labelKey: 'import.stock.recount', noteKey: null }],
      lastPairing: [],
    });

    const guide = await pending;
    expect(guide.switches).toEqual([
      { key: 'recount', labelKey: 'import.stock.recount', noteKey: null },
    ]);
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

    const note = {
      line: 2,
      column: 'reference',
      code: 'reference_given',
      params: { reference: 'ART-1' },
    };
    const change = { line: 3, column: 'name', before: 'Carthage', after: 'Carthage Conseil' };
    request.flush({
      committed: false,
      created: [2],
      updated: [3],
      unchanged: [4],
      rejected: [],
      changes: [change],
      notes: [note],
      alreadyImportedAt: null,
      encoding: 'utf-8',
    });
    const report = await pending;
    expect(report).toEqual({
      committed: false,
      run: null,
      created: [2],
      updated: [3],
      unchanged: [4],
      rejected: [],
      changes: [change],
      notes: [note],
      alreadyImportedAt: null,
      encoding: 'utf-8',
    });
  });

  it('sends each switch ticked as switches[], and reads when the same file was imported', async () => {
    const file = new File(['reference,stock_count\n'], 'stocks.csv', { type: 'text/csv' });
    const pending = api.run('c1', 'opening-stock', file, 'upsert', true, ['recount']);

    const request = http.expectOne('/api/companies/c1/imports/opening-stock');
    expect((request.request.body as FormData).getAll('switches[]')).toEqual(['recount']);
    request.flush({
      committed: false,
      created: [2],
      updated: [],
      rejected: [],
      notes: [],
      alreadyImportedAt: '2026-10-08T15:00:00+00:00',
    });

    expect((await pending).alreadyImportedAt).toBe('2026-10-08T15:00:00+00:00');
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
        { error: 'unknown_columns', columns: ['nom', 'prenom'], limit: null, encoding: 'ebcdic' },
        { status: 422, statusText: 'Unprocessable Content' },
      );

    const refused = (await pending.catch((error: unknown) => error)) as ImportNotKept;
    expect(refused).toBeInstanceOf(ImportNotKept);
    expect(refused.report).toBeNull();
    expect(refused.refusal).toEqual({
      reason: 'unknown_columns',
      columns: ['nom', 'prenom'],
      limit: null,
      encoding: null,
    });
  });

  it('sends the encoding chosen for a CSV, none when the API is to find it, and reads the one used', async () => {
    const file = new File(['x'], 'clients.csv', { type: 'text/csv' });
    const chosen = api.run('c1', 'customers', file, 'create', true, [], 'windows-1256');
    const sent = http.expectOne('/api/companies/c1/imports/customers');
    expect((sent.request.body as FormData).get('encoding')).toBe('windows-1256');
    sent.flush({
      committed: false,
      created: [2],
      updated: [],
      rejected: [],
      notes: [],
      alreadyImportedAt: null,
      encoding: 'windows-1256',
    });
    expect((await chosen).encoding).toBe('windows-1256');

    const found = api.run('c1', 'customers', file, 'create', true);
    const asked = http.expectOne('/api/companies/c1/imports/customers');
    expect((asked.request.body as FormData).has('encoding')).toBe(false);
    asked.flush(
      { error: 'unreadable_encoding', columns: [], limit: null, encoding: 'utf-8' },
      { status: 422, statusText: 'Unprocessable Content' },
    );
    const refused = (await found.catch((error: unknown) => error)) as ImportNotKept;
    expect(refused.refusal).toEqual({
      reason: 'unreadable_encoding',
      columns: [],
      limit: null,
      encoding: 'utf-8',
    });
  });

  it('reads the pairing the last import kept, a heading set aside included', async () => {
    const pending = api.guide('c1', 'customers');
    http.expectOne('/api/companies/c1/imports/customers').flush({
      subject: 'customers',
      identity: ['number'],
      maxRows: 2000,
      columns: [],
      switches: [],
      lastPairing: [
        { heading: 'Réf. client', column: 'number' },
        { heading: 'Remarque', column: '' },
      ],
    });

    expect((await pending).lastPairing).toEqual([
      { heading: 'Réf. client', column: 'number' },
      { heading: 'Remarque', column: '' },
    ]);
  });

  it('sends the pairing as one object of headings to columns, and nothing when there is none', async () => {
    const file = new File(['x'], 'clients.csv', { type: 'text/csv' });
    const paired = api.run('c1', 'customers', file, 'create', true, [], null, [
      { heading: 'Réf. client', column: 'number' },
      { heading: 'Remarque', column: '' },
    ]);
    const sent = http.expectOne('/api/companies/c1/imports/customers');
    expect(JSON.parse((sent.request.body as FormData).get('pairing') as string)).toEqual({
      'Réf. client': 'number',
      Remarque: '',
    });
    sent.flush({ committed: false, created: [2], updated: [], rejected: [], notes: [] });
    await paired;

    const plain = api.run('c1', 'customers', file, 'create', true, [], null, []);
    const asked = http.expectOne('/api/companies/c1/imports/customers');
    expect((asked.request.body as FormData).has('pairing')).toBe(false);
    asked.flush(
      { error: 'invalid_pairing', columns: ['Réf. client'], limit: null, encoding: 'utf-8' },
      { status: 422, statusText: 'Unprocessable Content' },
    );
    const refused = (await plain.catch((error: unknown) => error)) as ImportNotKept;
    expect(refused.refusal?.reason).toBe('invalid_pairing');
    expect(refused.refusal?.columns).toEqual(['Réf. client']);
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

  // docs/SPEC.md § 7, the step-up list: a file changing a vendor's bank details waits for the proof, and kept nothing.
  it('names the proof the API waits for before it keeps a file', async () => {
    const pending = api.run('c1', 'vendors', new File(['x'], 'vendors.csv'), 'upsert', false);
    http
      .expectOne('/api/companies/c1/imports/vendors')
      .flush({ error: 'step_up_required' }, { status: 403, statusText: 'Forbidden' });
    const refused = await pending.catch((error: unknown) => error);
    expect(refused).toEqual(new ImportRefused('step_up_required'));
  });

  it('reads the import kept from a committed run, and none from a preview', async () => {
    const kept = api.run('c1', 'customers', new File(['x'], 'f.csv'), 'create', false);
    http
      .expectOne('/api/companies/c1/imports/customers')
      .flush({ committed: true, run: 'r1', created: [2], updated: [], rejected: [], notes: [] });
    expect((await kept).run).toBe('r1');

    const preview = api.run('c1', 'customers', new File(['x'], 'f.csv'), 'create', true);
    http
      .expectOne('/api/companies/c1/imports/customers')
      .flush({ committed: false, run: null, created: [2], updated: [], rejected: [], notes: [] });
    expect((await preview).run).toBeNull();
  });

  it('reads « Imports récents » of the company', async () => {
    const pending = api.recent('c1');
    const run = {
      id: 'r1',
      subject: 'opening-stock',
      at: '2026-10-20T10:00:00+01:00',
      by: 'Sonia',
      created: 3,
      updated: 1,
      undoableUntil: '2026-10-21T10:00:00+01:00',
      undoneAt: null,
    };
    http.expectOne('/api/companies/c1/import-runs').flush([run]);

    expect(await pending).toEqual([run]);
  });

  it('posts « Annuler cet import » and reads what it did', async () => {
    const pending = api.undo('c1', 'r1');
    const request = http.expectOne('/api/companies/c1/import-runs/r1/undo');
    expect(request.request.method).toBe('POST');
    const answer = {
      removal: 'rm1',
      removed: [{ id: 'x1', label: 'CLI-0001 Carthage' }],
      kept: [],
      rowsKept: [{ label: 'VIS-6X40', reason: 'vendor_has_code' }],
      stockTakenBack: [{ reference: 'VIS-6X40', location: 'A1', quantity: '3' }],
      stockKept: [{ reference: 'P-OLD', location: 'A1', reason: 'below_zero' }],
      updated: 2,
    };
    request.flush(answer);

    expect(await pending).toEqual(answer);
  });

  it('names why an undo was refused, and asks for the proof the API wants', async () => {
    for (const [status, body, code] of [
      [409, { error: 'import_undone' }, 'import_undone'],
      [409, { error: 'import_final' }, 'import_final'],
      [403, { error: 'step_up_required' }, 'step_up_required'],
      [404, null, 'not_found'],
    ] as const) {
      const pending = api.undo('c1', 'r1');
      http
        .expectOne('/api/companies/c1/import-runs/r1/undo')
        .flush(body, { status, statusText: 'Refused' });
      await expect(pending).rejects.toEqual(new ImportRefused(code));
    }
  });
});
