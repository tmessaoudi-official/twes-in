// SPDX-License-Identifier: AGPL-3.0-or-later

import { describe, expect, it } from 'vitest';
import { minus, perUnit } from './per-unit';

describe('perUnit', () => {
  it('divides an amount by a quantity at the currency’s scale', () => {
    expect(perUnit('4284.000', '2.000', 3)).toBe('2142.000');
    expect(perUnit('2.38', '2', 2)).toBe('1.19');
  });

  it('rounds half away from zero', () => {
    expect(perUnit('1.000', '3', 3)).toBe('0.333');
    expect(perUnit('2.000', '3', 3)).toBe('0.667');
    expect(perUnit('0.005', '1', 2)).toBe('0.01');
    expect(perUnit('-0.005', '1', 2)).toBe('-0.01');
  });

  it('counts a quantity with decimals', () => {
    expect(perUnit('3.000', '2.5', 3)).toBe('1.200');
  });

  it('has no price for nothing', () => {
    expect(perUnit('3.000', '0.000', 3)).toBeNull();
    expect(perUnit('3.000', '', 3)).toBeNull();
  });
});

describe('minus', () => {
  it('takes one exact decimal from another', () => {
    expect(minus('3600.000', '360.000')).toBe('3240.000');
    expect(minus('1.5', '2.25')).toBe('-0.75');
  });
});
