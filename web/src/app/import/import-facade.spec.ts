// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { Feedback } from '../shared/feedback/feedback';
import { StepUp } from '../shared/step-up/step-up';
import { RecordDeletes } from '../shared/records/record-deletes';
import {
  effectToasts,
  failureToasts,
  offeredNext,
  provideQuietFeedback,
  type RecordedFeedback,
} from '../shared/testing/feedback';
import { ImportApi, ImportNotKept, ImportRefused } from './import-api';
import { ImportFacade } from './import-facade';
import type { ImportReport, ImportUndone } from './import-types';

// The proof dialog is answered by the test; `retrying` itself is StepUp's own.
const stepUp = Object.assign(Object.create(StepUp.prototype) as StepUp, {
  request: vi.fn<(intro?: string) => Promise<boolean>>(),
});

describe('ImportFacade', () => {
  const api = {
    guide: vi.fn(),
    run: vi.fn(),
    templateUrl: vi.fn(),
    recent: vi.fn(),
    undo: vi.fn(),
  };
  const records = {
    restore: vi.fn<(companyId: string, removal: { id: string }) => Promise<boolean>>(),
  };
  let facade: ImportFacade;

  const report = (rejected: ImportReport['rejected']): ImportReport => ({
    committed: false,
    run: null,
    created: [],
    updated: [],
    rejected,
    notes: [],
    alreadyImportedAt: null,
    encoding: null,
    changes: [],
    unchanged: [],
  });

  beforeEach(() => {
    api.guide.mockReset();
    api.run.mockReset();
    api.recent.mockReset().mockResolvedValue([]);
    api.undo.mockReset();
    records.restore.mockReset();
    TestBed.configureTestingModule({
      providers: [
        ...provideQuietFeedback(),
        { provide: ImportApi, useValue: api },
        { provide: StepUp, useValue: stepUp },
        { provide: RecordDeletes, useValue: records },
      ],
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
      new ImportNotKept(null, {
        reason: 'too_many_rows',
        columns: [],
        limit: 2000,
        encoding: 'utf-8',
      }),
    );

    await facade.run('c1', 'customers', new File(['x'], 'f.csv'), 'create', false);

    expect(facade.refusal()?.reason).toBe('too_many_rows');
    expect(facade.report()).toBeNull();
    expect(facade.error()).toBeNull();
  });

  it('says what was stored, and only when something was', async () => {
    api.run.mockResolvedValueOnce({
      committed: false,
      run: null,
      created: [2],
      updated: [],
      rejected: [],
      notes: [],
      alreadyImportedAt: null,
      encoding: null,
    });
    await facade.run('c1', 'customers', new File(['x'], 'f.csv'), 'create', true);
    expect((TestBed.inject(Feedback) as RecordedFeedback).said).toEqual([]);

    api.run.mockResolvedValueOnce({
      committed: true,
      run: 'r1',
      created: [2],
      updated: [3],
      rejected: [],
      notes: [],
      alreadyImportedAt: null,
      encoding: null,
    });
    await facade.run('c1', 'customers', new File(['x'], 'f.csv'), 'create', false);

    expect((TestBed.inject(Feedback) as RecordedFeedback).said).toEqual([
      { kind: 'success', key: 'import.stored', params: { created: 1, updated: 1 } },
    ]);
  });

  it('hands the switches ticked to the API with the run', async () => {
    api.run.mockResolvedValueOnce(report([]));
    const file = new File(['x'], 'f.csv');

    await facade.run('c1', 'opening-stock', file, 'upsert', true, ['recount'], null, [], {
      unreachable_gross: 'above',
    });

    expect(api.run).toHaveBeenCalledWith(
      'c1',
      'opening-stock',
      file,
      'upsert',
      true,
      ['recount'],
      null,
      [],
      { unreachable_gross: 'above' },
    );
  });

  it('hands the encoding chosen for a CSV to the API with the run', async () => {
    api.run.mockResolvedValueOnce(report([]));
    const file = new File(['x'], 'f.csv');

    await facade.run('c1', 'customers', file, 'create', true, [], 'windows-1256');

    expect(api.run).toHaveBeenCalledWith(
      'c1',
      'customers',
      file,
      'create',
      true,
      [],
      'windows-1256',
      [],
      {},
    );
  });

  it('hands the pairing of the file’s headings to the API with the run', async () => {
    api.run.mockResolvedValueOnce(report([]));
    const file = new File(['x'], 'f.csv');
    const pairing = [{ heading: 'Réf. client', column: 'number' }];

    await facade.run('c1', 'customers', file, 'create', true, [], null, pairing);

    expect(api.run).toHaveBeenCalledWith(
      'c1',
      'customers',
      file,
      'create',
      true,
      [],
      null,
      pairing,
      {},
    );
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

  // docs/SPEC.md § 7, the step-up list: a file changing a vendor's bank details waits for the proof, then goes again whole.
  it('asks for the proof the API wants, then sends the same file again', async () => {
    stepUp.request.mockReset().mockResolvedValue(true);
    const kept = { ...report([]), committed: true, run: 'r1', updated: [2] };
    api.run
      .mockRejectedValueOnce(new ImportRefused('step_up_required'))
      .mockResolvedValueOnce(kept);
    const file = new File(['x'], 'vendors.csv');

    await facade.run('c1', 'vendors', file, 'upsert', false);

    expect(stepUp.request).toHaveBeenCalledWith('step_up.intro_sensitive');
    expect(api.run).toHaveBeenCalledTimes(2);
    expect(api.run).toHaveBeenLastCalledWith(
      'c1',
      'vendors',
      file,
      'upsert',
      false,
      [],
      null,
      [],
      {},
    );
    expect(facade.report()).toEqual(kept);
  });

  it('says nothing was kept when the person does not give the proof', async () => {
    stepUp.request.mockReset().mockResolvedValue(false);
    api.run.mockRejectedValue(new ImportRefused('step_up_required'));

    await facade.run('c1', 'vendors', new File(['x'], 'vendors.csv'), 'upsert', false);

    expect(facade.error()).toBe('step_up_required');
    expect(facade.report()).toBeNull();
    expect(api.run).toHaveBeenCalledTimes(1);
  });

  describe('« Annuler cet import »', () => {
    const undone = (removal: string | null): ImportUndone => ({
      removal,
      removed: removal === null ? [] : [{ id: 'x1', label: 'CLI-0001 Carthage' }],
      kept: [],
      rowsKept: [],
      stockTakenBack: [],
      stockKept: [],
      updated: 0,
    });

    it('reads « Imports récents » again after an import is stored', async () => {
      api.run.mockResolvedValueOnce({ ...report([]), committed: true, run: 'r1', created: [2] });

      await facade.run('c1', 'customers', new File(['x'], 'f.csv'), 'create', false);

      expect(api.recent).toHaveBeenCalledWith('c1');
    });

    /** What went to the bin is put back from the toast, which stands the import again. */
    it('offers « Annuler » on the toast when the undo made a delete, and the toast puts it back', async () => {
      api.undo.mockResolvedValue(undone('rm1'));
      records.restore.mockResolvedValue(true);

      expect(await facade.undo('c1', 'r1')).toBe(true);

      expect(api.undo).toHaveBeenCalledWith('c1', 'r1');
      expect(effectToasts()).toEqual(['import.undo.done:annulable']);
      expect(facade.undone()?.removal).toBe('rm1');
      expect(api.recent).toHaveBeenCalledTimes(1);

      offeredNext()?.run();
      await vi.waitFor(() => expect(facade.undone()).toBeNull());
      expect(records.restore).toHaveBeenCalledWith('c1', { id: 'rm1' });
      expect(api.recent).toHaveBeenCalledTimes(2);
    });

    /** An opening stock's undo makes no delete: nothing on the toast pretends to put it back. */
    it('offers no « Annuler » when nothing went to the bin, and says it is corrected by importing again', async () => {
      api.undo.mockResolvedValue(undone(null));

      await facade.undo('c1', 'r1');

      expect(effectToasts()).toEqual(['import.undo.done:corrigeable']);
      expect(offeredNext()).toBeNull();
    });

    it('asks for the proof the API wants, then undoes', async () => {
      stepUp.request.mockReset().mockResolvedValue(true);
      api.undo
        .mockRejectedValueOnce(new ImportRefused('step_up_required'))
        .mockResolvedValueOnce(undone('rm1'));

      expect(await facade.undo('c1', 'r1')).toBe(true);

      expect(stepUp.request).toHaveBeenCalledWith('step_up.intro_change');
      expect(api.undo).toHaveBeenCalledTimes(2);
    });

    it('says why an undo was refused, and reads the list again', async () => {
      api.undo.mockRejectedValue(new ImportRefused('import_final'));

      expect(await facade.undo('c1', 'r1')).toBe(false);

      expect(failureToasts()).toEqual(['import.errors.import_final']);
      expect(facade.undone()).toBeNull();
      expect(api.recent).toHaveBeenCalledWith('c1');
    });
  });
});
