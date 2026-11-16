// SPDX-License-Identifier: AGPL-3.0-or-later

import { describe, expect, it } from 'vitest';
import {
  atScale,
  decimalOf,
  exactPriceFor,
  isBelow,
  markupOf,
  marginOf,
  profitOf,
} from './price-calculator-math';

describe('price calculator math', () => {
  it('reads an amount from what the form holds, and nothing from what is not one', () => {
    expect(decimalOf('12.500')).toBe('12.500');
    expect(decimalOf(' 3 ')).toBe('3');
    expect(decimalOf('')).toBeNull();
    expect(decimalOf(null)).toBeNull();
    expect(decimalOf('abc')).toBeNull();
    expect(decimalOf('1e2')).toBeNull();
    expect(decimalOf('-3')).toBeNull();
  });

  // Unblock page rule-23 (C103): money is a decimal string, so what the calculator shows is counted as exactly as the
  // price it writes, never through a float.
  it('counts profit, margin on the price and markup on the cost for the same pair, exactly', () => {
    expect(profitOf('60', '100', 3)).toBe('40.000');
    expect(profitOf('100', '80', 3)).toBe('-20.000');
    expect(profitOf('0.1', '0.3', 3)).toBe('0.200');
    expect(marginOf('60', '100', 2)).toBe('40.00');
    expect(markupOf('60', '100', 2)).toBe('66.67');
    expect(marginOf('100', '80', 2)).toBe('-25.00');
    expect(marginOf('10', '0', 2)).toBeNull();
    expect(markupOf('0', '10', 2)).toBeNull();
  });

  it('rounds half away from zero, where a float rounds 1.005 down, and says no sign for a nought', () => {
    expect(profitOf('1', '2.005', 2)).toBe('1.01');
    expect(profitOf('2.005', '1', 2)).toBe('-1.01');
    expect(markupOf('200', '200.01', 2)).toBe('0.01');
    expect(markupOf('200', '200.009', 2)).toBe('0.00');
    expect(markupOf('200', '199.991', 2)).toBe('0.00');
    expect(markupOf('3', '4', 1)).toBe('33.3');
    expect(atScale('83.3335', 3)).toBe('83.334');
    expect(atScale('12.5', 0)).toBe('13');
    expect(atScale('100', 3)).toBe('100.000');
  });

  it('compares two amounts at any scale', () => {
    expect(isBelow('400', '534.0000')).toBe(true);
    expect(isBelow('534', '534.0000')).toBe(false);
    expect(isBelow('534.0001', '534')).toBe(false);
  });

  it('round-trips: the price for a margin has that margin', () => {
    for (const percent of ['5', '25', '33.3', '90']) {
      const price = exactPriceFor('42', percent, 'margin', 4) ?? '';
      expect(Number(marginOf('42', price, 2))).toBeCloseTo(Number(percent), 2);
    }
    for (const percent of ['5', '25', '150']) {
      const price = exactPriceFor('42', percent, 'markup', 4) ?? '';
      expect(Number(markupOf('42', price, 2))).toBeCloseTo(Number(percent), 2);
    }
  });

  // Audit 2026-10-06, C-5: the price the calculator writes into the form is counted exactly from what was typed, never
  // through a float, which rounds 1.005 to 1.00.
  it('counts the price for a percentage exactly, rounded half up at the currency scale', () => {
    expect(exactPriceFor('60', '40', 'margin', 3)).toBe('100.000');
    expect(exactPriceFor('60', '40', 'markup', 3)).toBe('84.000');
    expect(exactPriceFor('1.005', '0', 'markup', 2)).toBe('1.01');
    expect(exactPriceFor('2.0005', '0', 'margin', 3)).toBe('2.001');
    expect(exactPriceFor('10', '33.333', 'margin', 3)).toBe('15.000');
    expect(exactPriceFor('0.1', '200', 'markup', 3)).toBe('0.300');
    expect(exactPriceFor('12.5', '0', 'markup', 0)).toBe('13');
  });

  it('counts no price where none exists or nothing is an amount', () => {
    expect(exactPriceFor('60', '100', 'margin', 3)).toBeNull();
    expect(exactPriceFor('60', '120', 'margin', 3)).toBeNull();
    expect(exactPriceFor('60', '-5', 'markup', 3)).toBeNull();
    expect(exactPriceFor('abc', '10', 'markup', 3)).toBeNull();
    expect(exactPriceFor('60', '1e2', 'markup', 3)).toBeNull();
    expect(exactPriceFor('', '10', 'markup', 3)).toBeNull();
  });
});
