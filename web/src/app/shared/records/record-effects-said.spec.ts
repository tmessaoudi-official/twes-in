// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import type { SaidEffect } from '../effects/effects';
import { Feedback } from '../feedback/feedback';
import { TextsParts } from '../i18n/texts-part';
import { provideQuietFeedback, type RecordedFeedback } from '../testing/feedback';
import { LINKS_CLEARED, LINKS_NOT_RESTORED } from './record-effects';
import { RecordEffectsSaid } from './record-effects-said';
import { RECORDS_TEXTS } from './records-texts';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      records: {
        tables: {
          product_category: '{{count}} sous-catégorie(s)',
          vendor: '{{count}} fournisseur(s)',
          other: '{{count}} autre(s) fiche(s)',
        },
      },
    });
  }
}

describe('RecordEffectsSaid', () => {
  const texts = { open: vi.fn() };

  beforeEach(() => {
    texts.open.mockReset().mockResolvedValue(undefined);
    TestBed.configureTestingModule({
      providers: [
        provideTranslateService({ lang: 'fr', loader: provideTranslateLoader(StaticLoader) }),
        provideQuietFeedback(),
        { provide: TextsParts, useValue: texts },
      ],
    });
  });

  const said = () =>
    (TestBed.inject(Feedback) as RecordedFeedback).said.map(({ kind, key, params }) => ({
      kind,
      key,
      params,
    }));
  const effect = (kind: string, table: string, count: number): SaidEffect => ({
    kind,
    id: 'r1',
    params: { table, column: 'parent_id', count },
  });
  const say = (effects: SaidEffect[]) => TestBed.inject(RecordEffectsSaid).said(effects);

  it('opens the records texts before it says anything', async () => {
    await say([effect(LINKS_CLEARED, 'vendor', 1)]);

    expect(texts.open).toHaveBeenCalledWith(RECORDS_TEXTS);
  });

  it('says every link a delete emptied, each table in words, in one sentence', async () => {
    await say([effect(LINKS_CLEARED, 'product_category', 2), effect(LINKS_CLEARED, 'vendor', 1)]);

    expect(said()).toEqual([
      {
        kind: 'notice',
        key: 'records.links_cleared',
        params: { links: '2 sous-catégorie(s) et 1 fournisseur(s)' },
      },
    ]);
  });

  it('says the links a restore left, apart from those it emptied', async () => {
    await say([
      effect(LINKS_CLEARED, 'vendor', 1),
      effect(LINKS_NOT_RESTORED, 'product_category', 1),
    ]);

    expect(said()).toEqual([
      { kind: 'notice', key: 'records.links_cleared', params: { links: '1 fournisseur(s)' } },
      {
        kind: 'notice',
        key: 'records.links_not_restored',
        params: { links: '1 sous-catégorie(s)' },
      },
    ]);
  });

  it('counts a table the screens do not name, or a folded answer, as other records', async () => {
    await say([
      effect(LINKS_CLEARED, 'stock_location', 3),
      { kind: LINKS_NOT_RESTORED, id: null, params: {}, count: 4 },
    ]);

    expect(said()).toEqual([
      { kind: 'notice', key: 'records.links_cleared', params: { links: '3 autre(s) fiche(s)' } },
      {
        kind: 'notice',
        key: 'records.links_not_restored',
        params: { links: '4 autre(s) fiche(s)' },
      },
    ]);
  });
});
