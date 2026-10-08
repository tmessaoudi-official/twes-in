// SPDX-License-Identifier: AGPL-3.0-or-later

import { sumQuantities } from './stock-quantities';

describe('sumQuantities', () => {
  it('adds the API’s decimal strings exactly, at three decimals', () => {
    // 0.1 + 0.2 in floating point is 0.30000000000000004: a total must never read like that.
    expect(sumQuantities(['0.100', '0.200'])).toBe('0.300');
    expect(sumQuantities(['8.000', '4.5', '12'])).toBe('24.500');
  });

  it('keeps a quantity below zero, and answers zero for nothing', () => {
    expect(sumQuantities(['2.000', '-3.250'])).toBe('-1.250');
    expect(sumQuantities(['-0.500'])).toBe('-0.500');
    expect(sumQuantities([])).toBe('0.000');
  });

  it('holds a total past what a float counts exactly', () => {
    expect(sumQuantities(['99999999999.999', '0.001'])).toBe('100000000000.000');
  });
});
