// SPDX-License-Identifier: AGPL-3.0-or-later

import { describe, expect, it } from 'vitest';
import {
  amountOf,
  atCurrencyScale,
  markupOf,
  marginOf,
  priceFor,
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

  it('finds the price for a margin and for a markup, which are not the same price', () => {
    expect(priceFor(60, 40, 'margin')).toBeCloseTo(100);
    expect(priceFor(60, 40, 'markup')).toBeCloseTo(84);
    expect(priceFor(50, 0, 'margin')).toBe(50);
    expect(priceFor(0, 30, 'markup')).toBe(0);
  });

  it('finds no price for a margin of a hundred percent, a negative percentage or a missing cost', () => {
    expect(priceFor(60, 100, 'margin')).toBeNull();
    expect(priceFor(60, 120, 'margin')).toBeNull();
    expect(priceFor(60, -5, 'markup')).toBeNull();
    expect(priceFor(Number.NaN, 10, 'markup')).toBeNull();
  });

  it('writes a price at the currency scale with a point, rounded half up', () => {
    expect(atCurrencyScale(100, 3)).toBe('100.000');
    expect(atCurrencyScale(83.3333, 3)).toBe('83.333');
    expect(atCurrencyScale(0.0005, 3)).toBe('0.001');
    expect(atCurrencyScale(12.5, 0)).toBe('13');
  });

  it('round-trips: the price for a margin has that margin', () => {
    for (const percent of [5, 25, 33.3, 90]) {
      const price = priceFor(42, percent, 'margin')!;
      expect(marginOf(42, price)).toBeCloseTo(percent);
    }
    for (const percent of [5, 25, 150]) {
      const price = priceFor(42, percent, 'markup')!;
      expect(markupOf(42, price)).toBeCloseTo(percent);
    }
  });
});
