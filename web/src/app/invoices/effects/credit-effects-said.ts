// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable } from '@angular/core';
import { EFFECT_TEXTS, type SaidEffect } from '../../shared/effects/effects';
import { Feedback } from '../../shared/feedback/feedback';
import { FormatFacade } from '../../shared/i18n/format-facade';
import { TextsParts } from '../../shared/i18n/texts-part';

/**
 * Says the credit a deleted payment gave back, after the page's own « Le paiement … est supprimé »: the customer's
 * balance moved too, on a screen nobody is looking at. Past what a header carries the API folds the kind, which is
 * said without its figure.
 */
@Injectable({ providedIn: 'root' })
export class CreditEffectsSaid {
  private readonly feedback = inject(Feedback);
  private readonly format = inject(FormatFacade);
  private readonly texts = inject(TextsParts);

  async said(effects: readonly SaidEffect[]): Promise<void> {
    await this.texts.open(EFFECT_TEXTS);
    const amount = effects.length === 1 ? effects[0]?.params['amount'] : undefined;
    if (typeof amount === 'string') {
      this.feedback.success('effects.credit.given_back', {
        amount: this.format.amount(amount, null),
      });
      return;
    }
    this.feedback.success('effects.credit.given_back_unnamed', {});
  }
}
