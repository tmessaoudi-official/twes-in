// SPDX-License-Identifier: AGPL-3.0-or-later

/** Where an item is kept: a cookie the API sets, or the browser's local or per-tab session storage. */
export type StoredKind = 'cookie' | 'local' | 'session';

/** How long it stays: until the browser closes or the person signs out, until the tab closes, or until cleared. */
export type StoredLasts = 'session' | 'tab' | 'kept';

export interface StoredItem {
  /** Names its purpose, `legal.stored.purpose.<id>`. */
  readonly id: string;
  /** As the browser shows it; a `<placeholder>` stands for what varies. */
  readonly name: string;
  readonly kind: StoredKind;
  readonly lasts: StoredLasts;
}

/**
 * Everything the product keeps on a visitor's device (docs/SPEC.md § 7, 2026-09-26 08:52, row 149), which the Cookies
 * page lists. Only the session cookie, strictly necessary, and the person's own display choices: nothing that needs
 * consent. `scripts/gates/stored-items.sh` reds when the code stores something this list does not declare, or this
 * list declares something nothing stores any more; keep one line per item, in this shape, for it to read.
 */
export const STORED_ITEMS: readonly StoredItem[] = [
  { id: 'session', name: 'twes_session', kind: 'cookie', lasts: 'session' },
  { id: 'display', name: 'twes.settings.<person>.<setting>', kind: 'local', lasts: 'kept' },
  { id: 'camera', name: 'twes.scan.camera', kind: 'local', lasts: 'kept' },
  { id: 'notice', name: 'twes.cookie-notice', kind: 'local', lasts: 'kept' },
  { id: 'customer_view', name: 'twes.customer-view', kind: 'session', lasts: 'tab' },
  { id: 'phone', name: 'twes.scan.phone', kind: 'session', lasts: 'tab' },
];
