// SPDX-License-Identifier: AGPL-3.0-or-later

import type {
  CellValue,
  ListColumn,
  ListDescriptor,
  ListFilter,
  ListFilterValues,
  ListPreferences,
  ListSort,
  ListView,
  ListViewDraft,
} from './list-types';
import { filterValues } from './list-filters';

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

/**
 * Rows matching every chosen filter option. A filter nobody declared, or an option it no longer offers, is ignored:
 * a saved view may outlive the configuration it was saved against.
 */
export function applyFilters<Row>(
  rows: readonly Row[],
  filters: readonly ListFilter<Row>[],
  chosen: ListFilterValues,
): Row[] {
  // A filter with several values keeps the rows holding ANY of them; different filters narrow together.
  const held = (filter: ListFilter<Row>): string[] =>
    (filter.multiple ? filterValues(chosen[filter.id]) : [chosen[filter.id] ?? '']).filter(
      (value) => filter.options.some((option) => option.value === value),
    );
  const active = filters.filter((filter) => held(filter).length > 0);
  return rows.filter((row) =>
    active.every((filter) => {
      const value = filter.value(row);
      return value !== null && held(filter).includes(value);
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

export class InvalidViewName extends Error {
  constructor() {
    super('a saved view needs a name');
    this.name = 'InvalidViewName';
  }
}

/**
 * The views with this draft saved: a name already used (ignoring case and accents) is replaced in place and keeps
 * its id, any other name is appended under `newId`. Throws InvalidViewName for a blank name.
 */
export function saveView(
  views: readonly ListView[],
  draft: ListViewDraft,
  newId: string,
): ListView[] {
  const name = draft.name.trim();
  if (name === '') throw new InvalidViewName();
  const existing = views.find((view) => fold(view.name) === fold(name));
  const saved: ListView = { ...draft, name, id: existing?.id ?? newId };
  return existing
    ? views.map((view) => (view.id === existing.id ? saved : view))
    : [...views, saved];
}

export function removeView(views: readonly ListView[], id: string): ListView[] {
  return views.filter((view) => view.id !== id);
}

/** What `fitColumns` weighs of one column shown: its width, how long it stays (0 first kept), whether it may go. */
export interface FitColumn {
  id: string;
  width: number;
  priority: number;
  hideable: boolean;
}

/**
 * The width a column takes before fitting: as wide as its widest value shown, so `fitColumns` gives a whole column
 * way rather than cut one, never narrower than it declares, and at most `cap`. A width a person chose is theirs, even
 * one that cuts. `widest` is undefined until the column's cells have been measured.
 */
export function fittedWidth(
  declared: number,
  widest: number | undefined,
  chosen: boolean,
  cap: number,
): number {
  if (chosen) return declared;
  return Math.max(declared, Math.min(widest ?? 0, cap));
}

/**
 * Every amount column's width: an amount up to ten million with three decimals, « 9 999 999,999 », takes 138 px in
 * the screen's own font, padding included, so no realistic figure is cut.
 */
export const AMOUNT_WIDTH = 140;

/** How long each priority a column declares stays on a narrow card: the highest goes last. */
export const FIT_PRIORITY: Readonly<Record<NonNullable<ListColumn<unknown>['priority']>, number>> =
  {
    high: 0,
    normal: 1,
    low: 2,
  };

/**
 * The columns hidden so the rest fit the card whole: a cut value reads as another value (« 23/09/20… »), so a column
 * either shows its width or gives way entirely. The lowest priority goes first, and among equals the last declared;
 * a column that cannot give way stays, and past it the card scrolls. Null or 0 is a card not measured yet.
 */
export function fitColumns(
  columns: readonly FitColumn[],
  reserved: number,
  card: number | null,
): ReadonlySet<string> {
  const hidden = new Set<string>();
  if (card === null || card <= 0) return hidden;
  let total = reserved + columns.reduce((sum, column) => sum + column.width, 0);
  const candidates = columns
    .map((column, index) => ({ column, index }))
    .filter(({ column }) => column.hideable)
    .sort((a, b) => b.column.priority - a.column.priority || b.index - a.index);
  for (const { column } of candidates) {
    if (total <= card) break;
    hidden.add(column.id);
    total -= column.width;
  }
  return hidden;
}
