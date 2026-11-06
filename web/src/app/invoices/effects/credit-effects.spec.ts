// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { EFFECT_TEXTS } from '../../shared/effects/effects';
import { Feedback } from '../../shared/feedback/feedback';
import { FormatFacade } from '../../shared/i18n/format-facade';
import { TextsParts } from '../../shared/i18n/texts-part';
import { provideQuietFeedback, type RecordedFeedback } from '../../shared/testing/feedback';
import { CREDIT_GIVEN_BACK, CreditEffects } from './credit-effects';

describe('CreditEffects', () => {
  const texts = { open: vi.fn() };

  beforeEach(() => {
    texts.open.mockReset().mockResolvedValue(undefined);
    TestBed.configureTestingModule({
      providers: [
        provideQuietFeedback(),
        { provide: TextsParts, useValue: texts },
        { provide: FormatFacade, useValue: { amount: (value: string) => `${value} TND` } },
      ],
    });
  });

  const said = () =>
    (TestBed.inject(Feedback) as RecordedFeedback).said.map(({ key, params }) => ({ key, params }));

  it('says the credit a deleted payment gave back to the customer, in its own texts', async () => {
    await TestBed.inject(CreditEffects).say([
      { kind: CREDIT_GIVEN_BACK, id: 'i1', params: { amount: '250.000' } },
    ]);

    expect(texts.open).toHaveBeenCalledWith(EFFECT_TEXTS);
    expect(said()).toEqual([
      { key: 'effects.credit.given_back', params: { amount: '250.000 TND' } },
    ]);
  });

  it('says it without a figure when the answer folded it', async () => {
    await TestBed.inject(CreditEffects).say([
      { kind: CREDIT_GIVEN_BACK, id: null, params: {}, count: 2 },
    ]);

    expect(said()).toEqual([{ key: 'effects.credit.given_back_unnamed', params: {} }]);
  });
});
