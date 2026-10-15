// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * What one of a line's units comes to: its amount over its quantity, at the currency's scale, rounded half away from
 * zero, in exact decimals and never through `Number`. Null for a quantity of nothing, which has no price per unit.
 */
export function perUnit(amount: string, quantity: string, scale: number): string | null {
  const [count, countScale] = units(quantity);
  if (count === 0n) return null;
  const [money, moneyScale] = units(amount);
  const numerator = money * 10n ** BigInt(countScale + scale);
  const denominator = count * 10n ** BigInt(moneyScale);
  const negative = numerator < 0n !== denominator < 0n;
  const top = numerator < 0n ? -numerator : numerator;
  const bottom = denominator < 0n ? -denominator : denominator;
  const rounded = (2n * top + bottom) / (2n * bottom);
  return written(negative && rounded !== 0n ? -rounded : rounded, scale);
}

/** One exact decimal less another, at the finer of their two scales. */
export function minus(from: string, taken: string): string {
  const [a, aScale] = units(from);
  const [b, bScale] = units(taken);
  const scale = Math.max(aScale, bScale);
  return written(a * 10n ** BigInt(scale - aScale) - b * 10n ** BigInt(scale - bScale), scale);
}

/** A decimal as a whole number of its smallest unit, and how many decimals that unit is; an empty one is nought. */
function units(value: string): [bigint, number] {
  const trimmed = value.trim();
  if (trimmed === '') return [0n, 0];
  const negative = trimmed.startsWith('-');
  const [whole, fraction = ''] = trimmed.replace(/^[-+]/, '').split('.');
  const count = BigInt((whole || '0') + fraction);
  return [negative ? -count : count, fraction.length];
}

function written(value: bigint, scale: number): string {
  const negative = value < 0n;
  const digits = (negative ? -value : value).toString().padStart(scale + 1, '0');
  const body = scale === 0 ? digits : `${digits.slice(0, -scale)}.${digits.slice(-scale)}`;
  return negative ? `-${body}` : body;
}
