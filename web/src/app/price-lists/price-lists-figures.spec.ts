// SPDX-License-Identifier: AGPL-3.0-or-later

import { describe, expect, it } from 'vitest';
import { rowFigures } from './price-lists-figures';

describe('rowFigures', () => {
  const product = { unitPriceNet: '890.0000', costPrice: '534.0000' };

  it('says the shelf price, the change against it and the margin the price leaves', () => {
    const figures = rowFigures('800', product);
    expect(figures).toEqual({
      shelf: '890.0000',
      cost: '534.0000',
      changePercent: expect.closeTo(-10.11236, 4),
      marginPercent: 33.25,
      belowCost: false,
    });
  });

  it('flags a price under the cost, which leaves a negative margin', () => {
    const figures = rowFigures('400', product);
    expect(figures?.belowCost).toBe(true);
    expect(figures?.marginPercent).toBeCloseTo(-33.5, 5);
  });

  it('does not flag a price equal to the cost', () => {
    expect(rowFigures('534', product)?.belowCost).toBe(false);
  });

  it('leaves out the cost, the margin and the flag when the person may not read costs', () => {
    const figures = rowFigures('400', { unitPriceNet: '890.0000', costPrice: null });
    expect(figures).toEqual({
      shelf: '890.0000',
      cost: null,
      changePercent: expect.closeTo(-55.056, 2),
      marginPercent: null,
      belowCost: false,
    });
  });

  it('has nothing to say for a product it knows no figures of, or a price not typed yet', () => {
    expect(rowFigures('400', null)).toBeNull();
    expect(rowFigures('', product)).toEqual({
      shelf: '890.0000',
      cost: '534.0000',
      changePercent: null,
      marginPercent: null,
      belowCost: false,
    });
  });

  it('leaves the change out for a shelf price of zero', () => {
    expect(rowFigures('10', { unitPriceNet: '0.0000', costPrice: null })?.changePercent).toBeNull();
  });
});
