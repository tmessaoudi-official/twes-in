// SPDX-License-Identifier: AGPL-3.0-or-later

/** What an establishment holds of a product whose stock is kept, counted in its stock unit. */
export interface OnHand {
  readonly unitId: string;
  readonly onHand: string;
}

/** What a line says of stock: what is on hand of its product and, where the line moves stock, what the document leaves. */
export interface LineStock extends OnHand {
  /** On hand less every line of the document taking the product in its stock unit; null where this line moves none. */
  readonly left: string | null;
}

/** One line as stock reads it. */
export interface StockLine {
  /** '' for a line naming no product. */
  readonly productId: string;
  readonly unitId: string;
  readonly quantity: string;
  /** Whether the document takes this line's goods out once it is issued, when the line is in the stock unit. */
  readonly takes: boolean;
}

/** A quantity as a line takes it: a decimal with at most three places; anything else is not a quantity yet. */
const QUANTITY = /^(0|[1-9][0-9]{0,9})([.][0-9]{1,3})?$/;

/** A non-negative decimal string as thousandths, so quantities add exactly, never as floats. */
function thousandths(value: string): bigint {
  const [whole, fraction = ''] = value.trim().split('.');
  return BigInt(whole || '0') * 1000n + BigInt((fraction + '000').slice(0, 3));
}

/** A signed decimal string as thousandths, exactly. */
function signedThousandths(value: string): bigint {
  const trimmed = value.trim();
  return trimmed.startsWith('-') ? -thousandths(trimmed.slice(1)) : thousandths(trimmed);
}

function fromThousandths(value: bigint): string {
  const sign = value < 0n ? '-' : '';
  const digits = (value < 0n ? -value : value).toString().padStart(4, '0');
  return `${sign}${digits.slice(0, -3)}.${digits.slice(-3)}`;
}

/**
 * What each line says of stock, from what the establishment holds of the products whose stock is kept: nothing for a
 * line naming no such product. No unit converts into another, so only a line that takes goods out in its product's
 * stock unit is told what the document leaves, and only such lines are taken off it; a quantity still being typed takes
 * nothing off.
 */
export function stockOfLines(
  lines: readonly StockLine[],
  onHand: ReadonlyMap<string, OnHand>,
): (LineStock | null)[] {
  const moves = (line: StockLine, stock: OnHand): boolean =>
    line.takes && line.unitId === stock.unitId;
  const taken = new Map<string, bigint>();
  for (const line of lines) {
    const stock = onHand.get(line.productId);
    if (stock === undefined || !moves(line, stock) || !QUANTITY.test(line.quantity.trim()))
      continue;
    taken.set(line.productId, (taken.get(line.productId) ?? 0n) + thousandths(line.quantity));
  }
  return lines.map((line) => {
    const stock = line.productId === '' ? undefined : onHand.get(line.productId);
    if (stock === undefined) return null;
    const left = moves(line, stock)
      ? fromThousandths(signedThousandths(stock.onHand) - (taken.get(line.productId) ?? 0n))
      : null;
    return { unitId: stock.unitId, onHand: stock.onHand, left };
  });
}
