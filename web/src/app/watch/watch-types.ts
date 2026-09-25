// SPDX-License-Identifier: AGPL-3.0-or-later

/** One condition to watch, as the API worked it out: its kind, what it is about and its figures. */
export interface WatchItem {
  readonly kind: string;
  readonly subjectId: string | null;
  readonly params: Readonly<Record<string, string | number>>;
}

/** « À surveiller »: what the signed-in member should look at in the company now (docs/SPEC.md § 7, 2026-09-24 12:10). */
export interface WatchList {
  readonly count: number;
  readonly items: readonly WatchItem[];
}

/** Why the list could not be read. */
export type WatchError = 'unavailable';

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
