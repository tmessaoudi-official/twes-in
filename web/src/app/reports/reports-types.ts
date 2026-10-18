// SPDX-License-Identifier: AGPL-3.0-or-later

/** How a column's figures read: a name, a key the screen translates, a day, money, a quantity, a count or a span of days. */
export type ReportColumnKind =
  'label' | 'choice' | 'day' | 'amount' | 'quantity' | 'count' | 'days';

/** One column, with the values that make it say what it counts (an age bracket's days). */
export interface ReportColumn {
  readonly key: string;
  readonly kind: ReportColumnKind;
  readonly params: Readonly<Record<string, number>>;
}

/** A report the member may read, as the catalogue offers it. */
export interface ReportEntry {
  readonly key: string;
  /** Whether it can be narrowed to one establishment. */
  readonly byEstablishment: boolean;
  /** Whether it reads a period; a report that is not reads today, and has nothing to be compared with. */
  readonly periodic: boolean;
  readonly columns: readonly ReportColumn[];
}

export type ReportCell = string | number | null;

/** Where a row's figures lead: one of the app's lists narrowed to them, or the row's own documents (a null list). */
export interface ReportDrill {
  readonly list: string | null;
  readonly filters: Readonly<Record<string, string | readonly string[]>>;
}

export interface ReportRow {
  readonly key: string;
  readonly cells: Readonly<Record<string, ReportCell>>;
  /** The same row in the period compared: null with no comparison, or where that period had no such row. */
  readonly previous: Readonly<Record<string, ReportCell>> | null;
  readonly drill: ReportDrill | null;
}

/** A report read over a period, beside the period compared when one was asked for. */
export interface ReportResult {
  readonly key: string;
  readonly from: string;
  readonly to: string;
  readonly comparisonFrom: string | null;
  readonly comparisonTo: string | null;
  readonly columns: readonly ReportColumn[];
  readonly rows: readonly ReportRow[];
  readonly totals: Readonly<Record<string, string | number>>;
  readonly previousTotals: Readonly<Record<string, string | number>> | null;
}

/** What the period is read against. */
export type ReportComparison = 'none' | 'previous' | 'year';

export const REPORT_COMPARISONS: readonly ReportComparison[] = ['none', 'previous', 'year'];

/** What a report is asked: its period (none for a report of today), the comparison and the establishment. */
export interface ReportQuery {
  readonly from: string | null;
  readonly to: string | null;
  readonly compare: ReportComparison;
  readonly establishment: string | null;
}

/** One document behind a row's figures, with what it brings to them. */
export interface ReportDocument {
  readonly kind: string;
  readonly id: string;
  readonly number: string | null;
  readonly party: string | null;
  readonly day: string;
  readonly amount: string;
}

/** A row's documents, the first ones when there are more than a dialog shows. */
export interface ReportDocuments {
  readonly documents: readonly ReportDocument[];
  readonly more: boolean;
}

/** A report nobody here may read, of a module switched off, or that does not exist: the API answers each the same. */
export class ReportGone extends Error {
  constructor() {
    super('There is no such report here.');
  }
}

/** A question the API refused (422): a period it cannot read, an establishment the report cannot be narrowed to. */
export class ReportRefused extends Error {
  constructor() {
    super('The report cannot be read so.');
  }
}
