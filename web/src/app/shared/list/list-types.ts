// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ActionConfirm } from '../actions/screen-action';

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

/**
 * What a row's own controls do (docs/SPEC.md § 7, 2026-09-19 23:16, design review finding 1). A list declares its
 * actions once; the trailing column shows the frequent ones as buttons and folds the rest into a "⋮" menu, and
 * row 45 gives the same declaration to the toolbar, the palette, the shortcuts and the "?" sheet.
 *
 * `rare` is what sends an action into the menu: an action used on most visits is a button, one used occasionally is
 * not worth the width it costs every row. Anything destructive is `rare` whatever its frequency, because a button
 * sitting under the pointer is the wrong place for it.
 */
export interface RowAction<Row> {
  id: string;
  /** A translation key; the label names the action, and is the accessible name of its icon button. */
  label: string;
  /**
   * What the label interpolates, for a label that names its row. A screen reader reads a list of icon buttons one
   * after another, so "Delete Zone 1" tells somebody which row they are on where eleven identical "Delete" do not.
   */
  labelParams?: (row: Row) => Record<string, string>;
  icon: string;
  /** Where it goes, for an action that is a navigation; a `routerLink` array. */
  link?: (row: Row) => unknown[];
  /** What the link carries in the address beside its path — the movements of THIS product, not of every product. */
  linkQuery?: (row: Row) => Record<string, string>;
  /** What it does, for an action that is not. Exactly one of `link` and `run` is given. */
  run?: (row: Row) => void;
  /** Folded into the "⋮" menu rather than shown as a button. Destructive actions are always rare. */
  rare?: boolean;
  /** Drawn as destructive, and never a visible button. */
  destructive?: boolean;
  /** Hidden for a row it cannot apply to — a paid invoice has nothing to pay. */
  shown?: (row: Row) => boolean;
  /**
   * Offered but refused for now: a save in flight, a row another person is changing. Distinct from `shown` on
   * purpose — a control that disappears while something is saving is a control a person cannot learn.
   */
  disabled?: (row: Row) => boolean;
  /**
   * What to ask before it runs, or nothing to run it straight away — the same field `ScreenAction` carries, so a
   * row's own delete follows the one rule the toolbar, the keyboard and the palette follow (row 45, row 106). A
   * function of the row, so the question can NAME it: a list of eleven identical "Supprimer ?" says nothing about
   * which one is about to go.
   */
  confirm?: (row: Row) => ActionConfirm;
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
  /**
   * What opens the record, as a `routerLink` array. The column named by `linkColumn` becomes a real link — so a
   * middle click, a copied address and a screen reader's link list all work, which an `(click)` handler on the row
   * gives none of. Selecting text and the row's own controls never open it (design review finding 1).
   */
  link?: (row: Row) => unknown[];
  /**
   * What the record's link carries in the address, merged into the list's own (its page, search and filters), or null
   * for a plain address: a list whose record opens as a sheet over it links to itself with the record named
   * (docs/SPEC.md § 7, 2026-09-26).
   */
  linkQuery?: (row: Row) => Record<string, string> | null;
  /** Which column carries the link; the first column that cannot be hidden when not given. */
  linkColumn?: string;
  /** The row's own actions; the trailing column renders them. */
  actions?: RowAction<Row>[];
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

/**
 * How many rows each option of a filter would list, for a list the API pages: counted by the API under the list's
 * other choices, since the page holds only a part of the rows. Keyed by filter id, then by option value; an option
 * left out shows no count.
 */
export type ListFacetCounts = Readonly<
  Record<string, { readonly total: number; readonly options: Readonly<Record<string, number>> }>
>;

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
