// SPDX-License-Identifier: AGPL-3.0-or-later

/** What a screen tells a person when a price-list call fails. */
export type PriceListsError = 'network' | 'not_found' | 'name_taken' | 'invalid' | 'refused';

/** One price of a list: from this quantity up, this net unit price, for this product. */
export interface PriceListItem {
  productId: string;
  productReference: string;
  productName: string;
  /** A decimal string, at most three decimals. */
  minQuantity: string;
  /** Net of tax, a decimal string, at most four decimals. */
  unitPriceNet: string;
}

/** What a sale of a product starts at: the net unit price, and the list that set it, null for the shelf price. */
export interface ResolvedPrice {
  unitPriceNet: string;
  priceListName: string | null;
}

/** Whom a list is for. */
export type PriceListScope = 'everyone' | 'group' | 'customer';

export interface PriceListRow {
  id: string;
  name: string;
  customerGroupId: string | null;
  customerId: string | null;
  /** `YYYY-MM-DD`, null from the start. */
  validFrom: string | null;
  /** `YYYY-MM-DD`, inclusive, null without end. */
  validTo: string | null;
  isActive: boolean;
  itemCount: number;
  /** Its prices: read on one list, null in the collection, which carries only their number. */
  items: PriceListItem[] | null;
}

/** What a save sends; `items` left null keeps the prices the list holds. */
export type PriceListInput = Omit<PriceListRow, 'id' | 'itemCount'>;

/** The scope a list is for, as its two foreign keys say it. */
export function scopeOf(
  list: Pick<PriceListRow, 'customerGroupId' | 'customerId'>,
): PriceListScope {
  if (list.customerId !== null) return 'customer';
  return list.customerGroupId !== null ? 'group' : 'everyone';
}
