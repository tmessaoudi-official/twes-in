// SPDX-License-Identifier: AGPL-3.0-or-later

import { describe, expect, it } from 'vitest';
import { stockOfLines, type StockLine } from './line-stock';

describe('stockOfLines', () => {
  const onHand = new Map([['p1', { unitId: 'u1', onHand: '6.500' }]]);
  const line = (values: Partial<StockLine>): StockLine => ({
    productId: 'p1',
    unitId: 'u1',
    quantity: '1',
    takes: true,
    ...values,
  });

  it('takes every line of the product in its stock unit off what is on hand, exactly', () => {
    expect(
      stockOfLines([line({ quantity: '3' }), line({ quantity: '4.25' })], onHand).map(
        (stock) => stock?.left,
      ),
    ).toEqual(['-0.750', '-0.750']);
    expect(stockOfLines([line({ quantity: '0.001' })], onHand)[0]).toEqual({
      unitId: 'u1',
      onHand: '6.500',
      left: '6.499',
    });
  });

  it('tells a line in another unit, or one that takes nothing, only what is there, and takes it off nothing', () => {
    const stocks = stockOfLines(
      [line({ quantity: '2' }), line({ unitId: 'u2', quantity: '9' }), line({ takes: false })],
      onHand,
    );
    expect(stocks.map((stock) => stock?.left)).toEqual(['4.500', null, null]);
    expect(stocks[1]).toEqual({ unitId: 'u1', onHand: '6.500', left: null });
  });

  it('says nothing of a line naming no product, or one whose stock is not known here', () => {
    expect(stockOfLines([line({ productId: '' }), line({ productId: 'p2' })], onHand)).toEqual([
      null,
      null,
    ]);
  });

  it('takes off nothing for a quantity that is not one yet, and reads a stock already below zero', () => {
    const below = new Map([['p1', { unitId: 'u1', onHand: '-2.000' }]]);
    expect(
      stockOfLines([line({ quantity: 'trois' }), line({ quantity: '1' })], below).map(
        (stock) => stock?.left,
      ),
    ).toEqual(['-3.000', '-3.000']);
  });
});
