// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../../auth/auth-facade';
import { EFFECT_TEXTS, type SaidEffect } from '../../shared/effects/effects';
import { Feedback } from '../../shared/feedback/feedback';
import { FormatFacade } from '../../shared/i18n/format-facade';
import { TextsParts } from '../../shared/i18n/texts-part';
import { provideQuietFeedback, type RecordedFeedback } from '../../shared/testing/feedback';
import {
  AT_REORDER_POINT,
  COUNT_DIFFERENCE,
  DOCUMENT_NOT_MOVED,
  LATE_COST_ENTERED,
  MERGE_CARRIED,
} from './stock-effects';
import { StockEffectsSaid } from './stock-effects-said';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({ effects: { count: { at: '{{difference}} à {{location}}' } } });
  }
}

describe('StockEffectsSaid', () => {
  const texts = { open: vi.fn() };
  let permissions: string[] = [];

  beforeEach(() => {
    texts.open.mockReset().mockResolvedValue(undefined);
    permissions = ['product.cost.read'];
    TestBed.configureTestingModule({
      providers: [
        provideTranslateService({ lang: 'fr', loader: provideTranslateLoader(StaticLoader) }),
        provideQuietFeedback(),
        { provide: TextsParts, useValue: texts },
        {
          provide: FormatFacade,
          useValue: {
            amount: (value: string, scale: number | null) => `${value}@${scale ?? 'as-is'}`,
            signed: (value: string, scale: number) => `±${value}@${scale}`,
            locale: () => 'fr-TN',
          },
        },
        {
          provide: AuthFacade,
          useValue: { hasPermission: (permission: string) => permissions.includes(permission) },
        },
      ],
    });
  });

  const said = () =>
    (TestBed.inject(Feedback) as RecordedFeedback).said.map(({ key, params }) => ({ key, params }));
  const effect = (kind: string, params: SaidEffect['params'], id = 'p1'): SaidEffect => ({
    kind,
    id,
    params,
  });
  const say = (effects: SaidEffect[]) => TestBed.inject(StockEffectsSaid).said(effects);

  it('opens its texts before it says anything', async () => {
    await say([effect(AT_REORDER_POINT, { establishment: 'Tunis' })]);

    expect(texts.open).toHaveBeenCalledWith(EFFECT_TEXTS);
  });

  it.each([
    [
      'both',
      { costOfSales: '15.0000', costBefore: '5.0000', cost: '8.0000' },
      { amount: '15.0000@as-is', before: '5.0000@as-is', after: '8.0000@as-is' },
    ],
    [
      'both_set',
      { costOfSales: '-2.5000', costBefore: null, cost: '8.0000' },
      { amount: '-2.5000@as-is', after: '8.0000@as-is' },
    ],
    [
      'cost_of_sales',
      { costOfSales: '15.0000', costBefore: '5.0000', cost: '5.0000' },
      { amount: '15.0000@as-is' },
    ],
    [
      'cost',
      { costOfSales: null, costBefore: '5.0000', cost: '8.0000' },
      { before: '5.0000@as-is', after: '8.0000@as-is' },
    ],
    [
      'cost_set',
      { costOfSales: null, costBefore: null, cost: '8.0000' },
      { after: '8.0000@as-is' },
    ],
  ])(
    'says what a late cost booked and moved: %s',
    async (key, params: SaidEffect['params'], shown) => {
      await say([effect(LATE_COST_ENTERED, params)]);

      expect(said()).toEqual([{ key: `effects.late_cost.${key}`, params: shown }]);
    },
  );

  it('says every place a count found a difference at, each figure signed at its own decimals', async () => {
    await say([
      effect(COUNT_DIFFERENCE, { difference: '2.000', location: 'A1' }),
      effect(COUNT_DIFFERENCE, { difference: '-0.250', location: 'Z1' }),
    ]);

    expect(said()).toEqual([
      {
        key: 'effects.count.differences',
        params: { differences: '±2.000@0 à A1 et ±-0.250@2 à Z1' },
      },
    ]);
  });

  it('says a point that puts the product on « À surveiller », and how many when the answer folded them', async () => {
    await say([effect(AT_REORDER_POINT, { establishment: 'Tunis' })]);
    await say([{ kind: AT_REORDER_POINT, id: null, params: {}, count: 4 }]);

    expect(said()).toEqual([
      { key: 'effects.reorder.one', params: { establishment: 'Tunis' } },
      { key: 'effects.reorder.many', params: { count: 4 } },
    ]);
  });

  it('says what a merge carried, its worth only to whoever reads costs', async () => {
    const carried = effect(MERGE_CARRIED, { quantity: '7.000', value: '14.0000' });
    await say([carried]);
    permissions = [];
    await say([carried]);

    expect(said()).toEqual([
      { key: 'effects.merge.carried', params: { quantity: '7.000@0', value: '14.0000@as-is' } },
      { key: 'effects.merge.carried_units', params: { quantity: '7.000@0' } },
    ]);
  });

  it('says a document whose stock did not all move, or did not move at all, by its number', async () => {
    await say([
      effect(
        DOCUMENT_NOT_MOVED,
        { document: 'invoice', outcome: 'left_out', number: 'FAC-1' },
        'i1',
      ),
    ]);
    await say([
      effect(
        DOCUMENT_NOT_MOVED,
        { document: 'delivery_note', outcome: 'failed', number: 'BL-2' },
        'd1',
      ),
    ]);
    await say([
      effect(DOCUMENT_NOT_MOVED, { document: 'invoice', outcome: 'failed', number: 'FAC-3' }, 'i3'),
      effect(DOCUMENT_NOT_MOVED, { document: 'invoice', outcome: 'failed', number: 'FAC-4' }, 'i4'),
    ]);

    expect(said()).toEqual([
      { key: 'effects.not_moved.left_out', params: { number: 'FAC-1' } },
      { key: 'effects.not_moved.failed', params: { number: 'BL-2' } },
      { key: 'effects.not_moved.many', params: { count: 2 } },
    ]);
  });

  it('says each kind of one answer in its own toast, once the one before it has closed', async () => {
    const feedback = TestBed.inject(Feedback);
    const closes: (() => void)[] = [];
    vi.spyOn(feedback, 'idle').mockImplementation(
      () => new Promise<void>((resolve) => closes.push(resolve)),
    );

    const saying = say([
      effect(AT_REORDER_POINT, { establishment: 'Tunis' }),
      effect(COUNT_DIFFERENCE, { difference: '1.000', location: 'A1' }),
    ]);
    await vi.waitFor(() => expect(closes).toHaveLength(1));
    expect(said()).toEqual([]);
    closes.shift()?.();
    await vi.waitFor(() => expect(closes).toHaveLength(1));
    expect(said().map(({ key }) => key)).toEqual(['effects.reorder.one']);
    closes.shift()?.();
    await saying;

    expect(said().map(({ key }) => key)).toEqual([
      'effects.reorder.one',
      'effects.count.differences',
    ]);
  });
});
