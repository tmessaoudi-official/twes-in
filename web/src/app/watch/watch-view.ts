// SPDX-License-Identifier: AGPL-3.0-or-later

import { formatAmount, formatDay } from '../shared/i18n/format';
import type { WatchItem } from './watch-types';

/** One condition as the screen says it: its sentence's key and figures, and where its link goes. */
export interface WatchLine {
  readonly key: string;
  readonly params: Readonly<Record<string, string | number>>;
  readonly link: readonly string[];
  readonly query: Readonly<Record<string, string>> | null;
}

/** "3.000" as a person counts it: "3"; the decimals a unit keeps stay. */
function plainQuantity(value: string): string {
  return value.includes('.') ? value.replace(/0+$/, '').replace(/\.$/, '') : value;
}

function text(item: WatchItem, name: string): string {
  const value = item.params[name];
  return value === undefined ? '' : String(value);
}

/**
 * Each figure in the locale, and the list it opens: a late customer opens the overdue invoices searched by the
 * customer's name, a product's condition opens the product, the unsold count the products.
 */
export function watchLine(item: WatchItem, locale: string): WatchLine {
  const params: Record<string, string | number> = { ...item.params };
  for (const name of ['amount'] as const) {
    if (name in params) params[name] = formatAmount(text(item, name), null, locale);
  }
  for (const name of ['quantity', 'onHand', 'point'] as const) {
    if (name in params) params[name] = formatAmount(plainQuantity(text(item, name)), null, locale);
  }
  if ('expiresOn' in params) params['expiresOn'] = formatDay(text(item, 'expiresOn'), locale);

  const expired = item.kind === 'stock.lot_expiring' && Number(item.params['days']) < 0;
  const key = `watch.kinds.${expired ? 'stock.lot_expired' : item.kind}`;

  if (item.kind === 'invoices.late_customer') {
    return {
      key,
      params,
      link: ['/invoices'],
      query: { status: 'overdue', q: text(item, 'customer') },
    };
  }
  if (item.subjectId !== null && item.kind.startsWith('stock.')) {
    return { key, params, link: ['/products', item.subjectId], query: null };
  }
  return { key, params, link: ['/products'], query: null };
}
