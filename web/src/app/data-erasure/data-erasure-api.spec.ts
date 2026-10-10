// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { DataErasureApi, ErasureRefused, type Erasure } from './data-erasure-api';

const ERASURE: Erasure = {
  id: 'e1',
  parts: ['stock_map'],
  counts: { stock_map: { floors: 1, places: 6, structures: 9 } },
  erasedAt: '2026-10-10T12:00:00+00:00',
  effectiveAt: '2026-10-11T12:00:00+00:00',
  state: 'pending',
  kinds: ['venue_area'],
};

describe('DataErasureApi', () => {
  let api: DataErasureApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(DataErasureApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('reads the counts, erases the parts asked, reads the pending one and undoes it', async () => {
    const preview = api.preview('c/1');
    http.expectOne('/api/companies/c%2F1/data-erasure').flush({ parts: [], pending: null });
    expect(await preview).toEqual({ parts: [], pending: null });

    const erased = api.erase('c1', ['stock_map', 'drafts']);
    const post = http.expectOne('/api/companies/c1/data-erasures');
    expect([post.request.method, post.request.body]).toEqual([
      'POST',
      { parts: ['stock_map', 'drafts'] },
    ]);
    post.flush(ERASURE, { status: 201, statusText: 'Created' });
    expect(await erased).toEqual(ERASURE);

    const none = api.pending('c1');
    http
      .expectOne('/api/companies/c1/data-erasures/pending')
      .flush(null, { status: 204, statusText: 'No Content' });
    expect(await none).toBeNull();

    const undone = api.undo('c1', 'e1');
    const undo = http.expectOne('/api/companies/c1/data-erasures/e1/undo');
    expect(undo.request.method).toBe('POST');
    undo.flush({ ...ERASURE, state: 'undone' });
    expect((await undone).state).toBe('undone');
  });

  it('reads each refusal by its code, and a conflict with the table in the way', async () => {
    const stepUp = api.erase('c1', ['drafts']);
    http
      .expectOne('/api/companies/c1/data-erasures')
      .flush({ error: 'step_up_required' }, { status: 403, statusText: 'Forbidden' });
    await expect(stepUp).rejects.toEqual(new ErasureRefused('step_up_required'));

    const conflict = api.undo('c1', 'e1');
    http
      .expectOne('/api/companies/c1/data-erasures/e1/undo')
      .flush({ error: 'erasure_conflict', table: 'venue_area' }, { status: 409, statusText: 'x' });
    const refused = await conflict.catch((error: unknown) => error);
    expect(refused).toBeInstanceOf(ErasureRefused);
    expect([(refused as ErasureRefused).code, (refused as ErasureRefused).table]).toEqual([
      'erasure_conflict',
      'venue_area',
    ]);

    const gone = api.undo('c1', 'e2');
    http
      .expectOne('/api/companies/c1/data-erasures/e2/undo')
      .flush({ detail: 'x' }, { status: 404, statusText: 'Not Found' });
    await expect(gone).rejects.toEqual(new ErasureRefused('not_found'));
  });
});
