// SPDX-License-Identifier: AGPL-3.0-or-later

import type { FieldRefusal } from '../shared/form/field-refusals';

/** Why a delivery note's validation or an invoice's issue was held back on stock, as the API names it on each line. */
const REFUSED = 'stock_short';
const TO_CONFIRM = 'stock_short_to_confirm';

/** A product the sale takes more of than its places hold, with the words the screen says it in. */
export interface ShortSaleLine {
  readonly productId: string;
  /** The place a count or a receipt puts the shortage right at. */
  readonly locationId: string;
  readonly params: {
    readonly product: string;
    readonly name: string;
    readonly wanted: string;
    readonly on_hand: string;
    readonly unit: string;
  };
}

export interface ShortSale {
  readonly lines: readonly ShortSaleLine[];
  /** Whether the person may sell anyway; a single line refused outright refuses the sale. */
  readonly confirmable: boolean;
}

/**
 * The sale beyond the stock a refusal names, or null when it names none: the company's « Sortie sans stock enregistré »
 * is « Confirmer » and the person may confirm (every line to confirm), or « Refuser », or « Confirmer » without the right.
 */
export function shortSaleIn(refusals: readonly FieldRefusal[]): ShortSale | null {
  const short = refusals.filter(
    (refusal) => refusal.reason === REFUSED || refusal.reason === TO_CONFIRM,
  );
  if (short.length === 0) return null;
  const lines = new Map<string, ShortSaleLine>();
  for (const { params } of short) {
    const productId = String(params['product_id'] ?? '');
    if (lines.has(productId)) continue;
    lines.set(productId, {
      productId,
      locationId: String(params['location_id'] ?? ''),
      params: {
        product: String(params['product'] ?? ''),
        name: String(params['name'] ?? ''),
        wanted: String(params['wanted'] ?? ''),
        on_hand: String(params['on_hand'] ?? ''),
        unit: String(params['unit'] ?? ''),
      },
    });
  }
  return {
    lines: [...lines.values()],
    confirmable: short.every((refusal) => refusal.reason === TO_CONFIRM),
  };
}

/**
 * The query a validation or an issue is sent again with once the person confirmed the sale beyond the stock, as a name
 * and a value the API adapters set on their request.
 */
export const SHORT_SALE_CONFIRMED = ['shortOfStock', 'confirmed'] as const;
