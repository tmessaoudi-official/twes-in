// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, Injector } from '@angular/core';
import type { EffectSayer, SaidEffect } from '../../shared/effects/effects';

/** A payment made of the customer's credit was deleted, and the credit is theirs again (`ManagePayments::delete`). */
export const CREDIT_GIVEN_BACK = 'invoice.credit_given_back';

/**
 * Says the credit a deleted payment gave back, whichever screen deleted it. Provided with the first page, so it loads
 * its words and their figures only when an answer first says it: they are read once the app runs, never while the
 * interceptors are built.
 */
@Injectable({ providedIn: 'root' })
export class CreditEffects implements EffectSayer {
  readonly kinds = [CREDIT_GIVEN_BACK] as const;
  private readonly injector = inject(Injector);

  async say(effects: readonly SaidEffect[]): Promise<void> {
    const { CreditEffectsSaid } = await import('./credit-effects-said');
    await this.injector.get(CreditEffectsSaid).said(effects);
  }
}
