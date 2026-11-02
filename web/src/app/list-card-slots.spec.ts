// SPDX-License-Identifier: AGPL-3.0-or-later

import { CUSTOMERS_LIST } from './customers/customer-forms';
import { DELIVERY_NOTES_LIST } from './delivery-notes/delivery-note-forms';
import { INVOICES_LIST } from './invoices/invoice-forms';
import { PORTFOLIO_LIST } from './invoices/portfolio-forms';
import { PRODUCTS_LIST } from './products/product-forms';
import { PURCHASES_LIST } from './purchases/purchase-order-forms';
import { QUOTES_LIST } from './quotes/quote-forms';
import { VENDORS_LIST } from './vendors/vendor-forms';
import { WATCH_SUBJECTS } from './watch/watch-subjects';
import type { ListDescriptor } from './shared/list/list-types';

/** The columns that hold a code a record is known by: a number, a reference, the number of the invoice it belongs to. */
const CODES = new Set(['number', 'reference', 'invoice', 'model']);

/**
 * A list whose link column is a code and whose card still leads with it, each with why. A stuck stock move names no
 * customer or vendor in its row: the document's number is all that tells it apart.
 */
const LEFT: Readonly<Record<string, string>> = {
  'watch-move-failed': 'its row names nothing but the document and the move',
};

const LISTS: readonly ListDescriptor<never>[] = [
  CUSTOMERS_LIST,
  VENDORS_LIST,
  INVOICES_LIST,
  QUOTES_LIST,
  DELIVERY_NOTES_LIST,
  PURCHASES_LIST,
  PRODUCTS_LIST,
  PORTFOLIO_LIST,
  ...Object.values(WATCH_SUBJECTS).map((subject) => subject.list),
] as unknown as readonly ListDescriptor<never>[];

function linkColumn(list: ListDescriptor<never>): string | null {
  if (list.link === undefined) return null;
  return (
    list.linkColumn ??
    list.columns.find((column) => column.hideable === false)?.id ??
    list.columns[0]?.id ??
    null
  );
}

describe('phone card slots', () => {
  it.each(LISTS.map((list) => [list.id, list] as const))(
    '%s leads its phone card with a name when its link column is a code',
    (id, list) => {
      const link = linkColumn(list);
      if (link === null || !CODES.has(link) || id in LEFT) return;
      expect(list.cardTitle, `${id} links on ${link}`).toBeDefined();
      expect(list.cardSubtitle ?? []).toContain(link);
    },
  );

  it.each(LISTS.map((list) => [list.id, list] as const))(
    '%s names only columns it has in its slots',
    (_id, list) => {
      const ids = new Set(list.columns.map((column) => column.id));
      const named = [
        list.cardTitle,
        ...(list.cardSubtitle ?? []),
        ...(list.cardAmount ?? []),
        list.cardStatus,
      ].filter((slot): slot is string => slot !== undefined);
      for (const slot of named) expect(ids.has(slot), slot).toBe(true);
    },
  );

  it('lists at least the documents, the people and the products', () => {
    const slotted = LISTS.filter((list) => list.cardTitle !== undefined).map((list) => list.id);
    expect(slotted.length).toBeGreaterThanOrEqual(10);
  });
});
