// SPDX-License-Identifier: AGPL-3.0-or-later

import { CapitalizePipe } from '../shared/i18n/capitalize-pipe';
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
 * translates a key, for the figures the API names by code: the kind of a cheque or traite, a stock move.
 */
export function watchRowView(
  row: WatchRow,
  index: number,
  figures: Pick<FormatFacade, 'amount' | 'day' | 'decimal'>,
  say: (key: string) => string = (key) => key,
): WatchRowView {
  const params = row.params;
  const cells: Record<string, string | number> = {};
  for (const name of [
    'customer',
    'vendor',
    'product',
    'reference',
    'establishment',
    'lot',
    'invoice',
    'number',
    'bank',
    'location',
    'email',
    'description',
    'payee',
    'note',
  ] as const) {
    if (name in params) cells[name] = text(params, name);
  }
  // A shipped role is translated as the members page names it, raised; a role the company made is its own name.
  if ('role' in params) {
    const role = text(params, 'role');
    cells['role'] =
      Number(params['roleBuiltIn'] ?? 0) === 1
        ? new CapitalizePipe().transform(say(`roles.${role}`))
        : role;
  }
  // What a record lacks, by code, and whether a person marked it, as its page names them.
  if ('reasons' in params) {
    cells['reasons'] = [
      ...(Number(params['marked'] ?? 0) === 1 ? [say('check.marked')] : []),
      ...text(params, 'reasons')
        .split(',')
        .filter((reason) => reason !== '')
        .map((reason) => say(`check.reasons.${reason}`)),
    ].join(', ');
  }
  if ('kind' in params) cells['kind'] = say(`invoices.instruments.kinds.${text(params, 'kind')}`);
  // Which of a document's stock moves failed, by code: its validation, its cancellation, its issue, its return.
  if ('move' in params) cells['move'] = say(`watch.moves.${text(params, 'move')}`);
  if ('invoices' in params) cells['invoices'] = Number(params['invoices']);
  // A draft waiting to be validated: how many lines it holds, and the whole hours since it was last saved.
  for (const name of ['lines', 'hours'] as const) {
    if (name in params) cells[name] = Number(params[name]);
  }
  if ('amount' in params) {
    const currency = text(params, 'currency');
    cells['amount'] = `${figures.amount(text(params, 'amount'), null)} ${currency}`.trim();
  }
  // A moved margin: the prices as the screen writes amounts, the margins as it writes decimals, with their sign.
  for (const name of ['price', 'cost', 'previousCost'] as const) {
    if (name in params) cells[name] = figures.amount(text(params, name), null);
  }
  for (const name of ['previousMargin', 'margin'] as const) {
    if (name in params) cells[name] = `${figures.decimal(text(params, name))} %`;
  }
  // A quantity with its unit, which the API names as the screens do: « -3 kg » is not « -3 » pieces.
  const unit = text(params, 'unit');
  for (const name of ['quantity', 'onHand', 'point'] as const) {
    if (name in params)
      cells[name] = `${figures.amount(plainQuantity(text(params, name)), null)} ${unit}`.trim();
  }
  if ('expiresOn' in params) cells['expiresOn'] = figures.day(text(params, 'expiresOn'));
  if ('receivedOn' in params) cells['receivedOn'] = figures.day(text(params, 'receivedOn'));
  if ('invitedOn' in params) cells['invitedOn'] = figures.day(text(params, 'invitedOn'));
  if ('expectedOn' in params) cells['expectedOn'] = figures.day(text(params, 'expectedOn'));
  if ('date' in params) cells['date'] = figures.day(text(params, 'date'));
  if ('days' in params) cells['days'] = Number(params['days']);
  return { id: String(index), row, cells };
}
