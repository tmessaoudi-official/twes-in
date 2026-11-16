// SPDX-License-Identifier: AGPL-3.0-or-later

import { decimalOf, isBelow, marginOf, markupOf } from '../products/price-calculator-math';

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
  /** The price against the shelf price, in percent at one decimal; null without a price or a shelf price above zero. */
  changePercent: string | null;
  /** The share of the price that is profit, in percent at one decimal; null without a price or a readable cost. */
  marginPercent: string | null;
  /** The price is under the cost, which is a sale at a loss. */
  belowCost: boolean;
}

/** The figures beside a row's price, or null when nothing is known of its product. */
export function rowFigures(price: string, product: ProductFigures | null): RowFigures | null {
  if (product === null) return null;
  const typed = decimalOf(price);
  const shelf = decimalOf(product.unitPriceNet);
  const cost = decimalOf(product.costPrice);
  return {
    shelf: product.unitPriceNet,
    cost: product.costPrice,
    // What the price adds to the shelf price is a markup on it, counted the same exact way.
    changePercent: typed !== null && shelf !== null ? markupOf(shelf, typed, 1) : null,
    marginPercent: typed !== null && cost !== null ? marginOf(cost, typed, 1) : null,
    belowCost: typed !== null && cost !== null && isBelow(typed, cost),
  };
}
