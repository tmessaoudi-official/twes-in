// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, Injector } from '@angular/core';
import type { EffectSayer, SaidEffect } from '../shared/effects/effects';

/** What a receipt says it did to a product's price or margin (`ChangeProductCost` on the API). */
export const PRICE_FOLLOWED = 'product.price_followed';
export const MARGIN_MOVED = 'product.margin_moved';

/**
 * Says what a request did to prices and margins, whichever screen received the goods. Provided with the first page, so
 * it reads the products' client only when a receipt first says something: a shop that never moves a cost never loads it.
 */
@Injectable({ providedIn: 'root' })
export class PriceEffects implements EffectSayer {
  readonly kinds = [PRICE_FOLLOWED, MARGIN_MOVED] as const;
  private readonly injector = inject(Injector);

  async say(effects: readonly SaidEffect[]): Promise<void> {
    const { PriceReviewOffer } = await import('./price-review-offer');
    await this.injector.get(PriceReviewOffer).said(effects);
  }
}
