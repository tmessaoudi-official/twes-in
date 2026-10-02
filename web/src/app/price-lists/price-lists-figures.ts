// SPDX-License-Identifier: AGPL-3.0-or-later

import { amountOf, marginOf } from '../products/price-calculator-math';

/** What a product's own price and cost are, as the products API answers them: the shelf price a list departs from. */
export interface ProductFigures {
  /** Net, a decimal string. */
  unitPriceNet: string;
  /** Null when the person may not read costs. */
  costPrice: string | null;
}

/** What a price typed on a row means beside the product's own: the starting point, the change and the margin left. */
export interface RowFigures {
  shelf: string;
  cost: string | null;
  /** The price against the shelf price, in percent; null without a price or a shelf price above zero. */
  changePercent: number | null;
  /** The share of the price that is profit; null without a price or a readable cost. */
  marginPercent: number | null;
  /** The price is under the cost, which is a sale at a loss. */
  belowCost: boolean;
}

/** The figures beside a row's price, or null when nothing is known of its product. */
export function rowFigures(price: string, product: ProductFigures | null): RowFigures | null {
  if (product === null) return null;
  const typed = amountOf(price);
  const shelf = amountOf(product.unitPriceNet);
  const cost = amountOf(product.costPrice);
  return {
    shelf: product.unitPriceNet,
    cost: product.costPrice,
    changePercent:
      typed !== null && shelf !== null && shelf > 0 ? ((typed - shelf) / shelf) * 100 : null,
    marginPercent: typed !== null && cost !== null ? marginOf(cost, typed) : null,
    belowCost: typed !== null && cost !== null && typed < cost,
  };
}
