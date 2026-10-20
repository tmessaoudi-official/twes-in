// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { RecordRemovalsApi, RemovalRefused, type RecordRemoval } from './record-removals-api';

const REMOVAL: RecordRemoval = {
  id: 'r1',
  subject: 'vendor',
  kind: 'delete',
  kept: null,
  items: [{ id: 'v1', label: 'F-001 Sotumag' }],
  removedAt: '2026-10-20T04:00:00+00:00',
  effectiveAt: '2026-10-21T04:00:00+00:00',
  state: 'pending',
};

describe('RecordRemovalsApi', () => {
  let api: RecordRemovalsApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(RecordRemovalsApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('deletes the records asked, lists what waits and restores one', async () => {
    const removed = api.remove('c/1', 'vendor', ['v1']);
    const post = http.expectOne('/api/companies/c%2F1/record-removals');
    expect([post.request.method, post.request.body]).toEqual([
      'POST',
      { subject: 'vendor', ids: ['v1'] },
    ]);
    post.flush(REMOVAL, { status: 201, statusText: 'Created' });
    expect(await removed).toEqual(REMOVAL);

    const listed = api.pending('c1');
    http.expectOne('/api/companies/c1/record-removals').flush([REMOVAL]);
    expect(await listed).toEqual([REMOVAL]);

    const restored = api.restore('c1', 'r/1');
    const restore = http.expectOne('/api/companies/c1/record-removals/r%2F1/restore');
    expect(restore.request.method).toBe('POST');
    restore.flush({ ...REMOVAL, state: 'restored' });
    expect((await restored).state).toBe('restored');
  });

  it('reads a record in use with what names it, and every other refusal by its code', async () => {
    const used = api.remove('c1', 'vendor', ['v1']);
    http.expectOne('/api/companies/c1/record-removals').flush(
      {
        error: 'record_used',
        records: [
          {
            id: 'v1',
            label: 'F-001 Sotumag',
            uses: [{ table: 'stock_movement', column: 'vendor_id', count: 2 }],
          },
        ],
      },
      { status: 409, statusText: 'Conflict' },
    );
    const inUse = await used.catch((error: unknown) => error);
    expect(inUse).toBeInstanceOf(RemovalRefused);
    expect(inUse).toMatchObject({
      code: 'record_used',
      records: [{ label: 'F-001 Sotumag', uses: [{ table: 'stock_movement', count: 2 }] }],
    });

    for (const [status, body, code] of [
      [409, { error: 'restore_conflict', table: 'vendor' }, 'restore_conflict'],
      [409, { error: 'removal_final' }, 'removal_final'],
      [404, null, 'not_found'],
      [500, null, 'network'],
    ] as const) {
      const refused = api.restore('c1', 'r1');
      http
        .expectOne('/api/companies/c1/record-removals/r1/restore')
        .flush(body, { status, statusText: 'Refused' });
      expect(await refused.catch((error: unknown) => (error as RemovalRefused).code)).toBe(code);
    }
  });

  it('merges into the record kept and reads a merge refusal by its code', async () => {
    const merge: RecordRemoval = {
      ...REMOVAL,
      subject: 'product_category',
      kind: 'merge',
      kept: { id: 'k1', label: 'Visserie' },
      items: [{ id: 'm1', label: 'Vis' }],
    };
    const merged = api.merge('c/1', 'product_category', 'k1', ['m1']);
    const post = http.expectOne('/api/companies/c%2F1/record-merges');
    expect([post.request.method, post.request.body]).toEqual([
      'POST',
      { subject: 'product_category', keptId: 'k1', ids: ['m1'] },
    ]);
    post.flush(merge, { status: 201, statusText: 'Created' });
    expect(await merged).toEqual(merge);

    for (const [status, body, code] of [
      [403, { error: 'step_up_required' }, 'step_up_required'],
      [409, { error: 'merge_cycle' }, 'merge_cycle'],
      [409, { error: 'merge_conflict', table: 'product_vendor' }, 'merge_conflict'],
      [422, { error: 'merge_into_itself' }, 'merge_into_itself'],
    ] as const) {
      const refused = api.merge('c1', 'product_category', 'k1', ['m1']);
      http
        .expectOne('/api/companies/c1/record-merges')
        .flush(body, { status, statusText: 'Refused' });
      expect(await refused.catch((error: unknown) => (error as RemovalRefused).code)).toBe(code);
    }
  });
});
