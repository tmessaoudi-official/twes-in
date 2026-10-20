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
  items: [{ id: 'v1', label: 'F-001 Sotumag' }],
  removedAt: '2026-10-20T04:00:00+00:00',
  effectiveAt: '2026-10-21T04:00:00+00:00',
  state: 'pending',
};

describe('RecordDeletes', () => {
  const api = { remove: vi.fn(), restore: vi.fn(), pending: vi.fn() };
  let deletes: RecordDeletes;
  let heard: ReturnType<typeof vi.spyOn>;

  beforeEach(() => {
    api.remove.mockReset();
    api.restore.mockReset();
    TestBed.configureTestingModule({
      providers: [
        provideTranslateService({ fallbackLang: 'fr' }),
        provideTranslateCompiler(PluralCompiler),
        provideQuietFeedback(),
        { provide: RecordRemovalsApi, useValue: api },
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
});
