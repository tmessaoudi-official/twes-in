// SPDX-License-Identifier: AGPL-3.0-or-later

import type { TableCount } from './record-removals-api';

/**
 * The tables the screens say in words, each worded under `records.tables`; any other is counted as « other records »,
 * so a table the API counts before the screens know it never shows its raw key.
 */
export const NAMED_TABLES = [
  'product',
  'product_category',
  'expense_category',
  'vendor',
  'stock_movement',
  'purchase_order',
  'expense',
  'product_vendor',
  'product_barcode',
  'product_photo',
  'product_home_location',
  'product_reorder_point',
  'product_cost_change',
  'invoice_line',
  'quote_line',
  'delivery_note_line',
  'purchase_order_line',
  'stock_lot',
  'price_list_item',
  'customer',
  'contact',
  'setting',
  'invoice',
  'quote',
  'delivery_note',
  'price_list',
  'customer_credit_entry',
] as const;

/** What names a record, by the key that words its table, the tables not worded summed as other records. */
export function tableCounts(
  uses: readonly TableCount[],
): readonly { readonly key: string; readonly count: number }[] {
  const counts = new Map<string, number>();
  for (const use of uses) {
    if (use.count <= 0) continue;
    const table = NAMED_TABLES.find((named) => named === use.table) ?? 'other';
    counts.set(table, (counts.get(table) ?? 0) + use.count);
  }
  return [...counts].map(([table, count]) => ({ key: `records.tables.${table}`, count }));
}
