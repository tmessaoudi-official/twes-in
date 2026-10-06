// SPDX-License-Identifier: AGPL-3.0-or-later

/** How a percentage is counted: on the selling price (margin) or on the cost (markup). */
export type PercentBasis = 'margin' | 'markup';

/** A money field as the form holds it, "12.5" or "", read as a number; null when it is not an amount. */
export function amountOf(text: string | null | undefined): number | null {
  const trimmed = (text ?? '').trim();
  if (trimmed === '') return null;
  const value = Number(trimmed);
  return Number.isFinite(value) ? value : null;
}

/** What is earned on one unit: the price less its cost. */
export function profitOf(cost: number, price: number): number {
  return price - cost;
}

/** The share of the selling price that is profit, in percent; null for a price that is not above zero. */
export function marginOf(cost: number, price: number): number | null {
  return price > 0 ? ((price - cost) / price) * 100 : null;
}

/** What the price adds to the cost, in percent of the cost; null for a cost that is not above zero. */
export function markupOf(cost: number, price: number): number | null {
  return cost > 0 ? ((price - cost) / cost) * 100 : null;
}

/** A number as an amount at the currency's scale, with the API's point: what the price field takes. */
export function atCurrencyScale(value: number, scale: number): string {
  return (Math.round(value * 10 ** scale) / 10 ** scale).toFixed(scale);
}

const COST = /^\d{1,10}(\.\d{1,4})?$/;
const PERCENT = /^\d{1,6}(\.\d{1,3})?$/;

/** A decimal string as an integer and the power of ten it is counted in. */
function scaled(text: string): [bigint, bigint] {
  const [whole, fraction = ''] = text.split('.');
  return [BigInt(whole + fraction), 10n ** BigInt(fraction.length)];
}

/**
 * The selling price that earns this percentage over the cost, counted exactly from the two as typed and rounded half up
 * at the currency's scale: what the calculator writes into the price field, so it never passes through a float. Null
 * where none exists (a margin of 100 % or more) or either is not an amount.
 */
export function exactPriceFor(
  costText: string,
  percentText: string,
  basis: PercentBasis,
  scale: number,
): string | null {
  const [cost, percent] = [costText.trim(), percentText.trim()];
  if (!COST.test(cost) || !PERCENT.test(percent)) return null;
  const [c, cUnit] = scaled(cost);
  const [p, pUnit] = scaled(percent);
  const hundred = 100n * pUnit;
  // price = c / cUnit × (hundred + p) / hundred for a markup, c / cUnit × hundred / (hundred − p) for a margin.
  const [numerator, denominator] =
    basis === 'markup'
      ? [c * (hundred + p), cUnit * hundred]
      : [c * hundred, cUnit * (hundred - p)];
  if (denominator <= 0n) return null;
  const unit = 10n ** BigInt(scale);
  const rounded = (2n * numerator * unit + denominator) / (2n * denominator);
  const whole = (rounded / unit).toString();
  return scale === 0 ? whole : `${whole}.${(rounded % unit).toString().padStart(scale, '0')}`;
}
