// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { Feedback } from '../shared/feedback/feedback';
import { provideQuietFeedback, type RecordedFeedback } from '../shared/testing/feedback';
import { ImportApi, ImportNotKept, ImportRefused } from './import-api';
import { ImportFacade } from './import-facade';
import type { ImportReport } from './import-types';

describe('ImportFacade', () => {
  const api = { guide: vi.fn(), run: vi.fn(), templateUrl: vi.fn() };
  let facade: ImportFacade;

  const report = (rejected: ImportReport['rejected']): ImportReport => ({
    committed: false,
    created: [],
    updated: [],
    rejected,
  });

  beforeEach(() => {
    api.guide.mockReset();
    api.run.mockReset();
    TestBed.configureTestingModule({
      providers: [...provideQuietFeedback(), { provide: ImportApi, useValue: api }],
    });
    facade = TestBed.inject(ImportFacade);
  });

  /** A file the rules would not keep is the answer the person came for, not a failure of the request. */
  it('holds a rejected run as the report, and leaves the error clear', async () => {
    const rejected = report([
      { line: 3, column: 'number', code: 'already_exists', params: {}, message: 'Already.' },
    ]);
    api.run.mockRejectedValue(new ImportNotKept(rejected, null));

    await facade.run('c1', 'customers', new File(['x'], 'f.csv'), 'create', false);

    expect(facade.report()).toEqual(rejected);
    expect(facade.error()).toBeNull();
    expect(facade.busy()).toBe(false);
  });

  it('holds a file refused whole as the refusal, with no report beside it', async () => {
    api.run.mockRejectedValue(
      new ImportNotKept(null, { reason: 'too_many_rows', columns: [], limit: 2000 }),
    );

    await facade.run('c1', 'customers', new File(['x'], 'f.csv'), 'create', false);

    expect(facade.refusal()?.reason).toBe('too_many_rows');
    expect(facade.report()).toBeNull();
    expect(facade.error()).toBeNull();
  });

  it('says what was stored, and only when something was', async () => {
    api.run.mockResolvedValueOnce({ committed: false, created: [2], updated: [], rejected: [] });
    await facade.run('c1', 'customers', new File(['x'], 'f.csv'), 'create', true);
    expect((TestBed.inject(Feedback) as RecordedFeedback).said).toEqual([]);

    api.run.mockResolvedValueOnce({ committed: true, created: [2], updated: [3], rejected: [] });
    await facade.run('c1', 'customers', new File(['x'], 'f.csv'), 'create', false);

    expect((TestBed.inject(Feedback) as RecordedFeedback).said).toEqual([
      { kind: 'success', key: 'import.stored', params: { created: 1, updated: 1 } },
    ]);
  });

  it('keeps a request that never ran as an error, and drops the answer before it', async () => {
    api.run.mockRejectedValueOnce(new ImportNotKept(report([]), null));
    await facade.run('c1', 'customers', new File(['x'], 'f.csv'), 'create', true);
    expect(facade.report()).not.toBeNull();

    api.run.mockRejectedValueOnce(new ImportRefused('not_found'));
    await facade.run('c1', 'customers', new File(['x'], 'f.csv'), 'create', true);

    expect(facade.error()).toBe('not_found');
    expect(facade.report()).toBeNull();
  });
});
