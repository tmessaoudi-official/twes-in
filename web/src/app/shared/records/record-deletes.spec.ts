// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import {
  provideTranslateCompiler,
  provideTranslateService,
  TranslateService,
} from '@ngx-translate/core';
import { Feedback } from '../feedback/feedback';
import { PluralCompiler } from '../i18n/plural-compiler';
import { LiveChanges } from '../realtime/live-changes';
import {
  effectToasts,
  failureToasts,
  offeredNext,
  provideQuietFeedback,
  successToasts,
} from '../testing/feedback';
import type { RecordedFeedback } from '../testing/feedback';
import { MatDialog } from '@angular/material/dialog';
import { of } from 'rxjs';
import { StepUp } from '../step-up/step-up';
import { RecordDeletes } from './record-deletes';
import { RecordRemovalsApi, RemovalRefused, type RecordRemoval } from './record-removals-api';

/** The French texts the refusal is built from; the parity spec holds the shipped files to these keys. */
const FR = {
  records: {
    used_by: '«\u00a0{{name}}\u00a0» figure dans {{uses}}',
    tables: {
      stock_movement:
        '{{count}} {count, plural, one {mouvement de stock} other {mouvements de stock}}',
      purchase_order:
        '{{count}} {count, plural, one {commande fournisseur} other {commandes fournisseur}}',
      other: '{{count}} {count, plural, one {autre fiche} other {autres fiches}}',
    },
  },
};

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

describe('RecordDeletes', () => {
  const api = {
    remove: vi.fn(),
    restore: vi.fn(),
    pending: vi.fn(),
    merge: vi.fn(),
    previewMerge: vi.fn(),
  };
  // The real retry over a faked dialog: what is under test is when the proof is asked, not the dialog.
  const dialog = { open: vi.fn() };
  const stepUp = { request: vi.fn(), retrying: StepUp.prototype.retrying };
  let deletes: RecordDeletes;
  let heard: ReturnType<typeof vi.spyOn>;

  beforeEach(() => {
    api.remove.mockReset();
    api.restore.mockReset();
    api.merge.mockReset();
    api.previewMerge.mockReset();
    stepUp.request.mockReset().mockResolvedValue(true);
    TestBed.configureTestingModule({
      providers: [
        provideTranslateService({ fallbackLang: 'fr' }),
        provideTranslateCompiler(PluralCompiler),
        provideQuietFeedback(),
        { provide: RecordRemovalsApi, useValue: api },
        { provide: StepUp, useValue: stepUp },
        { provide: MatDialog, useValue: dialog },
      ],
    });
    const translate = TestBed.inject(TranslateService);
    translate.setTranslation('fr', FR);
    translate.use('fr');
    heard = vi.spyOn(TestBed.inject(LiveChanges), 'changedHere');
    deletes = TestBed.inject(RecordDeletes);
  });

  const lastParams = (): Record<string, unknown> | undefined =>
    (TestBed.inject(Feedback) as RecordedFeedback).said.at(-1)?.params;

  it('deletes without asking, says it can be taken back, and its « Annuler » puts the record back', async () => {
    api.remove.mockResolvedValue(REMOVAL);
    api.restore.mockResolvedValue({ ...REMOVAL, state: 'restored' });

    expect(await deletes.remove('c1', 'vendor', REMOVAL.items)).toBe(true);

    expect(api.remove).toHaveBeenCalledWith('c1', 'vendor', ['v1']);
    expect(effectToasts()).toEqual(['records.deleted_one:annulable']);
    expect(lastParams()).toEqual({ name: 'F-001 Sotumag' });
    expect(heard).toHaveBeenCalledWith(['vendor', 'record_removal'], 'vendor.deleted');

    const undo = offeredNext();
    expect(undo?.key).toBe('records.undo');
    undo?.run();
    await vi.waitFor(() => expect(successToasts().at(-1)).toBe('records.restored_one'));
    expect(api.restore).toHaveBeenCalledWith('c1', 'r1');
    expect(heard).toHaveBeenCalledWith(['vendor', 'record_removal'], 'vendor.restored');
  });

  it('counts several records rather than naming them', async () => {
    api.remove.mockResolvedValue({
      ...REMOVAL,
      items: [...REMOVAL.items, { id: 'v2', label: 'F-002 Visserie' }],
    });

    await deletes.remove('c1', 'vendor', REMOVAL.items);

    expect(effectToasts()).toEqual(['records.deleted_many:annulable']);
    expect(lastParams()).toEqual({ count: 2 });
  });

  it('names what still uses each record, in words and counts, when nothing is deleted', async () => {
    api.remove.mockRejectedValue(
      new RemovalRefused('record_used', [
        {
          id: 'v1',
          label: 'F-001 Sotumag',
          uses: [
            { table: 'stock_movement', column: 'vendor_id', count: 2 },
            { table: 'purchase_order', column: 'vendor_id', count: 1 },
            { table: 'something_new', column: 'vendor_id', count: 1 },
          ],
        },
      ]),
    );

    expect(await deletes.remove('c1', 'vendor', REMOVAL.items)).toBe(false);

    expect(failureToasts()).toEqual(['records.in_use']);
    expect(lastParams()).toEqual({
      records:
        '«\u00a0F-001 Sotumag\u00a0» figure dans 2 mouvements de stock, 1 commande fournisseur et 1 autre fiche',
    });
    expect(heard).not.toHaveBeenCalled();
  });

  it('says why a restore was refused', async () => {
    api.restore.mockRejectedValue(new RemovalRefused('restore_conflict'));

    expect(await deletes.restore('c1', REMOVAL)).toBe(false);

    expect(failureToasts()).toEqual(['records.errors.restore_conflict']);
    expect(heard).not.toHaveBeenCalled();
  });

  const KEPT = { id: 'k1', label: 'Visserie' };
  const MERGE: RecordRemoval = {
    ...REMOVAL,
    id: 'm1',
    subject: 'product_category',
    kind: 'merge',
    kept: KEPT,
    items: [{ id: 'c2', label: 'Vis' }],
  };

  it('merges once who is at the screen is proved, and its « Annuler » undoes the merge after a proof too', async () => {
    api.merge
      .mockRejectedValueOnce(new RemovalRefused('step_up_required'))
      .mockResolvedValueOnce(MERGE);
    api.restore
      .mockRejectedValueOnce(new RemovalRefused('step_up_required'))
      .mockResolvedValueOnce({ ...MERGE, state: 'restored' });

    expect(await deletes.merge('c1', 'product_category', KEPT, MERGE.items)).toBe(true);

    expect(api.merge).toHaveBeenCalledTimes(2);
    expect(api.merge).toHaveBeenLastCalledWith('c1', 'product_category', 'k1', ['c2']);
    expect(stepUp.request).toHaveBeenCalledWith('step_up.intro_change');
    expect(effectToasts()).toEqual(['records.merged_one:annulable']);
    expect(lastParams()).toEqual({ name: 'Vis', kept: 'Visserie' });
    expect(heard).toHaveBeenCalledWith(
      ['product_category', 'record_removal'],
      'product_category.merged',
    );

    offeredNext()?.run();
    await vi.waitFor(() => expect(successToasts().at(-1)).toBe('records.merge_undone'));
    expect(lastParams()).toEqual({ kept: 'Visserie' });
    expect(api.restore).toHaveBeenCalledTimes(2);
    expect(heard).toHaveBeenCalledWith(
      ['product_category', 'record_removal'],
      'product_category.merge_undone',
    );
  });

  it('counts several records merged, and merges nothing when the proof is not given', async () => {
    api.merge.mockResolvedValueOnce({
      ...MERGE,
      items: [...MERGE.items, { id: 'c3', label: 'Clous' }],
    });
    await deletes.merge('c1', 'product_category', KEPT, MERGE.items);
    expect(effectToasts()).toEqual(['records.merged_many:annulable']);
    expect(lastParams()).toEqual({ count: 2, kept: 'Visserie' });

    api.merge.mockRejectedValueOnce(new RemovalRefused('step_up_required'));
    stepUp.request.mockResolvedValueOnce(false);
    expect(await deletes.merge('c1', 'product_category', KEPT, MERGE.items)).toBe(false);
    expect(api.merge).toHaveBeenCalledTimes(2);
    expect(failureToasts()).toEqual(['records.errors.step_up_required']);
  });

  it('says why a merge was refused', async () => {
    api.merge.mockRejectedValue(new RemovalRefused('merge_cycle'));

    expect(await deletes.merge('c1', 'product_category', KEPT, MERGE.items)).toBe(false);

    expect(failureToasts()).toEqual(['records.errors.merge_cycle']);
    expect(stepUp.request).not.toHaveBeenCalled();
  });

  it('names what the records disagree on when it says it, and the plain refusal otherwise', async () => {
    api.merge.mockRejectedValueOnce(new RemovalRefused('merge_mismatch', [], 'identifiers'));
    expect(await deletes.merge('c1', 'vendor', KEPT, MERGE.items)).toBe(false);
    api.merge.mockRejectedValueOnce(new RemovalRefused('merge_mismatch', [], 'colour'));
    expect(await deletes.merge('c1', 'vendor', KEPT, MERGE.items)).toBe(false);

    expect(failureToasts()).toEqual([
      'records.mismatch.identifiers',
      'records.errors.merge_mismatch',
    ]);
  });

  it('says what the record kept did since, when its merge is no longer undone', async () => {
    api.restore.mockRejectedValue(
      new RemovalRefused('merge_transacted', [], null, [
        { table: 'stock_movement', count: 2 },
        { table: 'purchase_order', count: 1 },
      ]),
    );

    expect(await deletes.restore('c1', MERGE)).toBe(false);

    expect(failureToasts()).toEqual(['records.traded_since']);
    expect(lastParams()).toEqual({ uses: '2 mouvements de stock et 1 commande fournisseur' });
    expect(heard).not.toHaveBeenCalled();
  });

  it('merges the records but the one the dialog kept, and nothing when it is closed', async () => {
    api.merge.mockResolvedValue(MERGE);
    const candidates = [
      { ...KEPT, moves: [] },
      { id: 'c2', label: 'Vis', moves: [{ table: 'product', count: 2 }] },
    ];
    dialog.open.mockReturnValueOnce({ afterClosed: () => of(KEPT) });

    expect(
      await deletes.offerMerge('c1', { subject: 'product_category', candidates, settings: true }),
    ).toBe(true);
    expect(dialog.open.mock.calls[0][1]).toMatchObject({
      data: { subject: 'product_category', candidates },
    });
    expect(api.merge).toHaveBeenCalledWith('c1', 'product_category', 'k1', ['c2']);

    dialog.open.mockReturnValueOnce({ afterClosed: () => of(null) });
    expect(
      await deletes.offerMerge('c1', { subject: 'product_category', candidates, settings: true }),
    ).toBe(false);
    expect(api.merge).toHaveBeenCalledTimes(1);
  });

  it('asks the API what a merge of aliases moves and leaves, offers it, and merges into the one kept', async () => {
    const preview = [
      { id: 'v1', label: 'F-001 Sotumag', moves: [], stays: [] },
      {
        id: 'v2',
        label: 'F-002 Sotumag Sfax',
        moves: [{ table: 'purchase_order', count: 1 }],
        stays: [{ table: 'expense', count: 3 }],
      },
    ];
    api.previewMerge.mockResolvedValue(preview);
    api.merge.mockResolvedValue({ ...MERGE, subject: 'vendor' });
    dialog.open.mockReturnValueOnce({
      afterClosed: () => of({ id: 'v1', label: 'F-001 Sotumag' }),
    });

    expect(
      await deletes.offerMergeOf('c1', 'vendor', ['v1', 'v2'], { settings: false, aliased: true }),
    ).toBe(true);

    expect(api.previewMerge).toHaveBeenCalledWith('c1', 'vendor', ['v1', 'v2']);
    expect(dialog.open.mock.calls.at(-1)?.[1]).toMatchObject({
      data: { subject: 'vendor', candidates: preview, settings: false, aliased: true },
    });
    expect(api.merge).toHaveBeenCalledWith('c1', 'vendor', 'v1', ['v2']);
  });

  it('opens nothing when the preview is refused, and says why', async () => {
    api.previewMerge.mockRejectedValue(new RemovalRefused('not_found'));
    dialog.open.mockClear();

    expect(
      await deletes.offerMergeOf('c1', 'vendor', ['v1', 'v2'], { settings: false, aliased: true }),
    ).toBe(false);

    expect(dialog.open).not.toHaveBeenCalled();
    expect(failureToasts()).toEqual(['records.errors.not_found']);
  });
});
