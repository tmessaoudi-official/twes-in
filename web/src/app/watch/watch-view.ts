// SPDX-License-Identifier: AGPL-3.0-or-later

import type { FormatFacade } from '../shared/i18n/format-facade';
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
 * Each figure as the screen writes figures (`FormatFacade`: the locale, or the person's chosen formats), and the list it opens: a late customer opens the overdue invoices searched by the
 * customer's name, a product's condition opens the product, the unsold count the products.
 */
export function watchLine(
  item: WatchItem,
  figures: Pick<FormatFacade, 'amount' | 'day'>,
): WatchLine {
  const params: Record<string, string | number> = { ...item.params };
  for (const name of ['amount'] as const) {
    if (name in params) params[name] = figures.amount(text(item, name), null);
  }
  for (const name of ['quantity', 'onHand', 'point'] as const) {
    if (name in params) params[name] = figures.amount(plainQuantity(text(item, name)), null);
  }
  if ('expiresOn' in params) params['expiresOn'] = figures.day(text(item, 'expiresOn'));

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
