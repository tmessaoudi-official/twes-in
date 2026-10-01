// SPDX-License-Identifier: AGPL-3.0-or-later

/** One subject of « À surveiller »: a kind of condition, and how many rows it has (docs/SPEC.md § 7, the subject pages). */
export interface WatchSubject {
  readonly kind: string;
  readonly count: number;
}

/**
 * « À surveiller » in summary: one count per subject the signed-in member may see, most pressing first, and the
 * whole. It carries no row: the home reads it on every load, and a subject's rows come a page at a time.
 */
export interface WatchSummary {
  readonly count: number;
  readonly subjects: readonly WatchSubject[];
}

/** One thing to act on, as the API worked it out: its kind, what it is about and its figures. */
export interface WatchRow {
  readonly kind: string;
  readonly subjectId: string | null;
  readonly params: Readonly<Record<string, string | number>>;
}

/** One page of a subject's rows and how many rows the whole subject holds. */
export interface WatchRowPage {
  readonly rows: readonly WatchRow[];
  readonly total: number;
}

/** Why the summary could not be read. */
export type WatchError = 'unavailable';

/** The API's one answer for a subject that is not there: dealt with, switched off, or not for this role. */
export class WatchSubjectGone extends Error {
  constructor() {
    super('This watch subject is not there.');
  }
}

/** What a condition on this list reads: a change to any of them may add or remove one. */
export const WATCHED_KINDS = [
  'invoice',
  'payment',
  'customer',
  'stock',
  'delivery_note',
  'product',
  'product_reorder_point',
  'setting',
] as const;
