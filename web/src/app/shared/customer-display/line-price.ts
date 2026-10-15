// SPDX-License-Identifier: AGPL-3.0-or-later

import type { LineFigures } from '../documents/document-figures';
import { minus, perUnit } from '../documents/per-unit';
import type { DisplayItem } from './customer-display';

/**
 * The line a scan went onto at the price it charges for one, as its figures work it out: after its price list, its
 * own discount and its share of the document's, with its taxes and without. Null until its figures are known.
 */
export function linePriced(
  name: string,
  quantity: string,
  figures: LineFigures | null | undefined,
  scale: number,
): DisplayItem | null {
  if (figures === null || figures === undefined) return null;
  const unitPrice = perUnit(figures.total, quantity, scale);
  const unitPriceNet = perUnit(minus(figures.net, figures.documentDiscount), quantity, scale);
  if (unitPrice === null || unitPriceNet === null) return null;
  return { name, quantity, unitPrice, unitPriceNet, priced: 'line' };
}
