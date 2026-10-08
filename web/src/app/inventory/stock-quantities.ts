// SPDX-License-Identifier: AGPL-3.0-or-later

/** How many decimals the API keeps every stock quantity at. */
const SCALE = 3;

/**
 * The sum of quantities as the API writes them, worked in thousandths so it is exact: a figure on screen is never a
 * float's approximation of one.
 */
export function sumQuantities(values: readonly string[]): string {
  let total = 0n;
  for (const value of values) {
    const negative = value.startsWith('-');
    const [whole, fraction = ''] = value.replace(/^[-+]/, '').split('.');
    const thousandths = BigInt((whole || '0') + fraction.padEnd(SCALE, '0').slice(0, SCALE));
    total += negative ? -thousandths : thousandths;
  }
  const negative = total < 0n;
  const digits = (negative ? -total : total).toString().padStart(SCALE + 1, '0');

  return `${negative ? '-' : ''}${digits.slice(0, -SCALE)}.${digits.slice(-SCALE)}`;
}
