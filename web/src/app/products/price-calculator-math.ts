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

/**
 * The selling price that earns this percentage over the cost, or null when none does: a margin of 100 % or more needs
 * an infinite price, and a percentage below zero is a loss nobody sets a price for.
 */
export function priceFor(cost: number, percent: number, basis: PercentBasis): number | null {
  if (!(cost >= 0) || !(percent >= 0)) return null;
  if (basis === 'markup') return cost * (1 + percent / 100);
  return percent < 100 ? cost / (1 - percent / 100) : null;
}

/** A number as an amount at the currency's scale, with the API's point: what the price field takes. */
export function atCurrencyScale(value: number, scale: number): string {
  return (Math.round(value * 10 ** scale) / 10 ** scale).toFixed(scale);
}
