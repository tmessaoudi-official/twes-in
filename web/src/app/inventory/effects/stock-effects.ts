// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, Injector } from '@angular/core';
import type { EffectSayer, SaidEffect } from '../../shared/effects/effects';

/** A late receipt cost booked a share to the cost of sales, or moved the product's cost (`KeepStock::enterCost`). */
export const LATE_COST_ENTERED = 'stock.late_cost_entered';
/** A count booked a difference at a place (`KeepStock`): the other keepers are told, the counter hears it here. */
export const COUNT_DIFFERENCE = 'stock.count_difference';
/** A reorder point written at or above what can be sold there puts the product on « À surveiller » (`KeepReorderPoints`). */
export const AT_REORDER_POINT = 'stock.at_reorder_point';
/** What a merge carried to the product kept (`CarryMergedStock`). */
export const MERGE_CARRIED = 'stock.merge_carried';
/** A document whose stock did not all move, or did not move at all (`TellStockKeepers`). */
export const DOCUMENT_NOT_MOVED = 'stock.document_not_moved';

/**
 * Says what a request did to the stock beyond its subject, whichever screen sent it. Provided with the first page, so it
 * loads its words and their figures only when an answer first says something of the stock.
 */
@Injectable({ providedIn: 'root' })
export class StockEffects implements EffectSayer {
  readonly kinds = [
    LATE_COST_ENTERED,
    COUNT_DIFFERENCE,
    AT_REORDER_POINT,
    MERGE_CARRIED,
    DOCUMENT_NOT_MOVED,
  ] as const;
  private readonly injector = inject(Injector);

  async say(effects: readonly SaidEffect[]): Promise<void> {
    const { StockEffectsSaid } = await import('./stock-effects-said');
    await this.injector.get(StockEffectsSaid).said(effects);
  }
}
