// SPDX-License-Identifier: AGPL-3.0-or-later

export type SortDirection = 'asc' | 'desc';

export type CellValue = string | number | null;

/**
 * One column of a list screen, as configuration: a screen declares its columns, an installation may append
 * custom ones (withCustomColumns), and each person hides, reorders and resizes them (ListPreferences).
 */
export interface ListColumn<Row> {
  id: string;
  /** A translation key for declared columns; custom columns carry their configured label. */
  label: string;
  value: (row: Row) => CellValue;
  sortable?: boolean;
  filterable?: boolean;
  /** False keeps the column on screen whatever a person hid; defaults to true. */
  hideable?: boolean;
  /** Hidden until a person places it. */
  defaultHidden?: boolean;
  width?: number;
  align?: 'start' | 'end';
}

export interface ListDescriptor<Row> {
  /** Also the preference key: `presentation.list.<id>`. */
  id: string;
  columns: ListColumn<Row>[];
  rowId: (row: Row) => string;
  pageSizes: number[];
  defaultSort?: ListSort;
  /** Choices shown beside the text filter, each narrowing the rows to one value. */
  filters?: ListFilter<Row>[];
}

export interface ListFilterOption {
  value: string;
  /** A translation key for declared options; custom options carry their configured label. */
  label: string;
}

/** One faceted filter of a list screen: the rows whose value equals the option a person picked. */
export interface ListFilter<Row> {
  id: string;
  label: string;
  value: (row: Row) => string | null;
  options: ListFilterOption[];
}

/** The option chosen per filter id; a filter with no entry shows every row. */
export type ListFilterValues = Record<string, string>;

export interface ListSort {
  column: string;
  direction: SortDirection;
}

/**
 * What one person chose for one list screen: columns they hid, the order they dragged them into, widths they set
 * and the sort they last used. Presentation only (docs/SPEC.md § 3 Settings); kept per user and per list.
 */
export interface ListPreferences {
  hidden: string[];
  order: string[];
  widths: Record<string, number>;
  sort: ListSort | null;
}

export const NO_LIST_PREFERENCES: ListPreferences = {
  hidden: [],
  order: [],
  widths: {},
  sort: null,
};

/**
 * A named snapshot of how one person looks at one list screen: the text filter, the chosen filter options and
 * the column layout with its sort. Kept per user and per list, under `presentation.list.<id>.views`.
 */
export interface ListView {
  id: string;
  name: string;
  query: string;
  filters: ListFilterValues;
  layout: ListPreferences;
}

export type ListViewDraft = Omit<ListView, 'id'>;

/**
 * What a list shown one page at a time by the API asks for (docs/SPEC.md § 7, lists at scale): the text filter, the
 * chosen filter options, the sort and the page. A screen turns it into its API's query.
 */
export interface ListQuery {
  query: string;
  filters: ListFilterValues;
  sort: ListSort | null;
  /** Numbered from 0, as the paginator counts. */
  pageIndex: number;
  pageSize: number;
}

/** One page of a list and how many rows the whole list holds. */
export interface ListPage<Row> {
  rows: Row[];
  total: number;
}
