// SPDX-License-Identifier: AGPL-3.0-or-later

import type { FormatFacade } from '../shared/i18n/format-facade';
import type { WatchRow } from './watch-types';

/** One row as the table shows it: each figure already written as the screen writes figures, keyed by what it is. */
export interface WatchRowView {
  /** Unique on its page: a product can be at its reorder point in two establishments. */
  readonly id: string;
  readonly row: WatchRow;
  readonly cells: Readonly<Record<string, string | number>>;
}

/** "3.000" as a person counts it: "3"; the decimals a unit keeps stay. */
function plainQuantity(value: string): string {
  return value.includes('.') ? value.replace(/0+$/, '').replace(/\.$/, '') : value;
}

function text(params: WatchRow['params'], name: string): string {
  const value = params[name];
  return value === undefined ? '' : String(value);
}

/**
 * Each figure as the screen writes figures (`FormatFacade`: the locale, or the person's chosen formats). Days are
 * signed by the API (a lot's date past is negative); the table says each in the direction its column names. `say`
 * translates a key, for the one figure the API names by code: the kind of a cheque or traite.
 */
export function watchRowView(
  row: WatchRow,
  index: number,
  figures: Pick<FormatFacade, 'amount' | 'day'>,
  say: (key: string) => string = (key) => key,
): WatchRowView {
  const params = row.params;
  const cells: Record<string, string | number> = {};
  for (const name of [
    'customer',
    'product',
    'reference',
    'establishment',
    'lot',
    'invoice',
    'number',
    'bank',
    'location',
  ] as const) {
    if (name in params) cells[name] = text(params, name);
  }
  if ('kind' in params) cells['kind'] = say(`invoices.instruments.kinds.${text(params, 'kind')}`);
  if ('invoices' in params) cells['invoices'] = Number(params['invoices']);
  if ('amount' in params) {
    const currency = text(params, 'currency');
    cells['amount'] = `${figures.amount(text(params, 'amount'), null)} ${currency}`.trim();
  }
  for (const name of ['quantity', 'onHand', 'point'] as const) {
    if (name in params) cells[name] = figures.amount(plainQuantity(text(params, name)), null);
  }
  if ('expiresOn' in params) cells['expiresOn'] = figures.day(text(params, 'expiresOn'));
  if ('receivedOn' in params) cells['receivedOn'] = figures.day(text(params, 'receivedOn'));
  if ('days' in params) cells['days'] = Number(params['days']);
  return { id: String(index), row, cells };
}
