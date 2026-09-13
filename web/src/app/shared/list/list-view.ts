// SPDX-License-Identifier: AGPL-3.0-or-later

import type {
  CellValue,
  ListColumn,
  ListDescriptor,
  ListPreferences,
  ListSort,
} from './list-types';

/**
 * What a list screen shows, as pure functions of its descriptor, a person's preferences and the rows:
 * the visible columns, then filter, sort and page. DataList only wires these to signals.
 */

export class DuplicateListColumn extends Error {
  constructor(id: string) {
    super(`list column "${id}" is already declared`);
    this.name = 'DuplicateListColumn';
  }
}

/**
 * Visible columns in display order: the person's order first (unknown ids ignored), then every column they
 * never placed in declared order. A column is hidden when the person hid it, or when it is hidden by default and
 * they never placed it; a column that is not hideable always shows. A chosen width wins over the declared one.
 */
export function resolveColumns<Row>(
  descriptor: ListDescriptor<Row>,
  preferences: ListPreferences,
): ListColumn<Row>[] {
  return orderColumns(descriptor, preferences).filter((column) =>
    isColumnVisible(column, preferences),
  );
}

/** Every column, visible or not, in display order with chosen widths applied: what the column chooser lists. */
export function orderColumns<Row>(
  descriptor: ListDescriptor<Row>,
  preferences: ListPreferences,
): ListColumn<Row>[] {
  const byId = new Map(descriptor.columns.map((column) => [column.id, column]));
  const placed = preferences.order.filter(
    (id, index, order) => byId.has(id) && order.indexOf(id) === index,
  );
  const placedSet = new Set(placed);

  return [
    ...placed.map((id) => byId.get(id)!),
    ...descriptor.columns.filter((column) => !placedSet.has(column.id)),
  ].map((column) => {
    const width = preferences.widths[column.id];
    return width === undefined ? column : { ...column, width };
  });
}

export function isColumnVisible<Row>(
  column: ListColumn<Row>,
  preferences: ListPreferences,
): boolean {
  if (column.hideable === false) return true;
  if (preferences.hidden.includes(column.id)) return false;
  return !column.defaultHidden || preferences.order.includes(column.id);
}

const collator = new Intl.Collator(undefined, { sensitivity: 'base', numeric: true });

function compareValues(left: CellValue, right: CellValue): number {
  if (typeof left === 'number' && typeof right === 'number') return left - right;
  return collator.compare(String(left), String(right));
}

const isEmpty = (value: CellValue): boolean => value === null || value === '';

/** A sorted copy; empty values go last in both directions. Unknown or unsortable columns leave the order alone. */
export function sortRows<Row>(
  rows: readonly Row[],
  columns: readonly ListColumn<Row>[],
  sort: ListSort | null,
): Row[] {
  const column = sort
    ? columns.find((candidate) => candidate.id === sort.column && candidate.sortable)
    : undefined;
  if (!sort || !column) return [...rows];
  const factor = sort.direction === 'asc' ? 1 : -1;

  return [...rows].sort((a, b) => {
    const left = column.value(a);
    const right = column.value(b);
    if (isEmpty(left) || isEmpty(right)) return Number(isEmpty(left)) - Number(isEmpty(right));
    return factor * compareValues(left, right);
  });
}

const fold = (text: string): string =>
  text
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '')
    .toLocaleLowerCase();

/** Rows whose filterable columns contain the query, ignoring case and accents. A blank query keeps everything. */
export function filterRows<Row>(
  rows: readonly Row[],
  columns: readonly ListColumn<Row>[],
  query: string,
): Row[] {
  const needle = fold(query.trim());
  if (needle === '') return [...rows];
  const searched = columns.filter((column) => column.filterable);

  return rows.filter((row) =>
    searched.some((column) => {
      const value = column.value(row);
      return value !== null && fold(String(value)).includes(needle);
    }),
  );
}

export interface Page<Row> {
  rows: Row[];
  pageIndex: number;
  total: number;
}

/** One page; a page index past the end lands on the last page (a filter may have shrunk the list). */
export function paginate<Row>(
  rows: readonly Row[],
  pageIndex: number,
  pageSize: number,
): Page<Row> {
  const lastPage = Math.max(0, Math.ceil(rows.length / pageSize) - 1);
  const index = Math.min(Math.max(0, pageIndex), lastPage);
  return {
    rows: rows.slice(index * pageSize, (index + 1) * pageSize),
    pageIndex: index,
    total: rows.length,
  };
}

/** The one place configured columns join a screen's declared ones. Throws DuplicateListColumn on a clash. */
export function withCustomColumns<Row>(
  descriptor: ListDescriptor<Row>,
  custom: readonly ListColumn<Row>[],
): ListDescriptor<Row> {
  const known = new Set(descriptor.columns.map((column) => column.id));
  for (const column of custom) {
    if (known.has(column.id)) throw new DuplicateListColumn(column.id);
    known.add(column.id);
  }
  return { ...descriptor, columns: [...descriptor.columns, ...custom] };
}
