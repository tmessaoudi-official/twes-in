// SPDX-License-Identifier: AGPL-3.0-or-later

import { typedText } from '../shared/form/typed-text';

/** How a percentage is counted: on the selling price (margin) or on the cost (markup). */
export type PercentBasis = 'margin' | 'markup';

/**
 * A money field as the form holds it, "12.5" or "", kept as the decimal string it is (at most ten digits and four
 * decimals, as the API stores a price or a cost); null when it is not an amount. Money is never read through a float.
 */
export function decimalOf(text: string | null | undefined): string | null {
  const trimmed = typedText(text ?? '');
  return AMOUNT.test(trimmed) ? trimmed : null;
}

/** What is earned on one unit, the price less its cost, at the currency's scale. */
export function profitOf(cost: string, price: string, scale: number): string {
  const [c, p, unit] = common(cost, price);
  return divided(p - c, unit, scale);
}

/** The share of the selling price that is profit, in percent at `decimals`; null for a price that is not above zero. */
export function marginOf(cost: string, price: string, decimals: number): string | null {
  const [c, p] = common(cost, price);
  return p > 0n ? divided(100n * (p - c), p, decimals) : null;
}

/** What the price adds to the cost, in percent of the cost at `decimals`; null for a cost that is not above zero. */
export function markupOf(cost: string, price: string, decimals: number): string | null {
  const [c, p] = common(cost, price);
  return c > 0n ? divided(100n * (p - c), c, decimals) : null;
}

/** An amount at the currency's scale, rounded half away from zero, with the API's point. */
export function atScale(amount: string, scale: number): string {
  const [value, unit] = scaled(amount);
  return divided(value, unit, scale);
}

/** Whether the first amount is under the second, read at whatever scale each is written. */
export function isBelow(amount: string, than: string): boolean {
  const [a, b] = common(amount, than);
  return a < b;
}

const AMOUNT = /^\d{1,10}(\.\d{1,4})?$/;
const COST = /^\d{1,10}(\.\d{1,4})?$/;
const PERCENT = /^\d{1,6}(\.\d{1,3})?$/;

/** A decimal string as an integer and the power of ten it is counted in. */
function scaled(text: string): [bigint, bigint] {
  const [whole, fraction = ''] = text.split('.');
  return [BigInt(whole + fraction), 10n ** BigInt(fraction.length)];
}

/** Two decimal strings counted in the same power of ten, and that power. */
function common(a: string, b: string): [bigint, bigint, bigint] {
  const [x, xUnit] = scaled(a);
  const [y, yUnit] = scaled(b);
  const unit = xUnit > yUnit ? xUnit : yUnit;
  return [x * (unit / xUnit), y * (unit / yUnit), unit];
}

/** A quotient at `decimals`, rounded half away from zero; a quotient that rounds to nought carries no sign. */
function divided(numerator: bigint, denominator: bigint, decimals: number): string {
  const negative = numerator < 0n !== denominator < 0n;
  const [n, d] = [
    numerator < 0n ? -numerator : numerator,
    denominator < 0n ? -denominator : denominator,
  ];
  const unit = 10n ** BigInt(decimals);
  const rounded = (2n * n * unit + d) / (2n * d);
  const whole = (rounded / unit).toString();
  const text =
    decimals === 0 ? whole : `${whole}.${(rounded % unit).toString().padStart(decimals, '0')}`;
  return negative && rounded !== 0n ? `-${text}` : text;
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
  const [cost, percent] = [typedText(costText), typedText(percentText)];
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
  return divided(numerator, denominator, scale);
}
