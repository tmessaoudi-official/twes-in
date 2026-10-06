// SPDX-License-Identifier: AGPL-3.0-or-later

import { describe, expect, it } from 'vitest';
import {
  amountOf,
  atCurrencyScale,
  exactPriceFor,
  markupOf,
  marginOf,
  profitOf,
} from './price-calculator-math';

describe('price calculator math', () => {
  it('reads an amount from what the form holds, and nothing from what is not one', () => {
    expect(amountOf('12.500')).toBe(12.5);
    expect(amountOf(' 3 ')).toBe(3);
    expect(amountOf('')).toBeNull();
    expect(amountOf(null)).toBeNull();
    expect(amountOf('abc')).toBeNull();
  });

  it('counts profit, margin on the price and markup on the cost for the same pair', () => {
    expect(profitOf(60, 100)).toBe(40);
    expect(marginOf(60, 100)).toBeCloseTo(40);
    expect(markupOf(60, 100)).toBeCloseTo(66.667, 3);
    expect(marginOf(100, 80)).toBeCloseTo(-25);
    expect(marginOf(10, 0)).toBeNull();
    expect(markupOf(0, 10)).toBeNull();
  });

  it('writes a price at the currency scale with a point, rounded half up', () => {
    expect(atCurrencyScale(100, 3)).toBe('100.000');
    expect(atCurrencyScale(83.3333, 3)).toBe('83.333');
    expect(atCurrencyScale(0.0005, 3)).toBe('0.001');
    expect(atCurrencyScale(12.5, 0)).toBe('13');
  });

  it('round-trips: the price for a margin has that margin', () => {
    for (const percent of [5, 25, 33.3, 90]) {
      const price = Number(exactPriceFor('42', String(percent), 'margin', 4));
      expect(marginOf(42, price)).toBeCloseTo(percent, 2);
    }
    for (const percent of [5, 25, 150]) {
      const price = Number(exactPriceFor('42', String(percent), 'markup', 4));
      expect(markupOf(42, price)).toBeCloseTo(percent, 2);
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
