// SPDX-License-Identifier: AGPL-3.0-or-later

/** Four decimals is the finest an amount here carries: counted in ten-thousandths, a sum stays exact. */
const UNIT = 10_000;

const counted = (amount: string): number => Math.round(Number(amount) * UNIT);

/**
 * What the customer owes once what is withheld at source is taken off the total: the figure the paid, credited and
 * left amounts add up to. Without a withholding it is the total itself.
 */
export function netToPay(total: string, withheld: readonly { amount: string }[]): string {
  const net = counted(total) - withheld.reduce((sum, each) => sum + counted(each.amount), 0);
  return (net / UNIT).toFixed(4);
}
