// SPDX-License-Identifier: AGPL-3.0-or-later

/** The largest count a scan takes: beyond it a slip of the finger is likelier than a till's real quantity. */
export const SCAN_COUNT_MAX = 9999;

/** How long typed digits wait for their times sign. */
export const SCAN_COUNT_WAIT_MS = 2000;

const TIMES = new Set(['*', 'x', 'X', '×']);

/**
 * A count typed by hand before a scan, as at a till (docs/SPEC.md § 7, 2026-09-23 09:30): "5" then "×" (or "*", or
 * "x") and the next scan counts five. Fed the keys a person types with no field focused; answers the count on the
 * times sign. Digits a scanner types are never fed here: the wedge claims them first.
 */
export class ScanCount {
  private digits = '';
  private lastAt = Number.NEGATIVE_INFINITY;

  read(key: string, at: number): number | null {
    if (/^\d$/.test(key)) {
      this.digits = at - this.lastAt < SCAN_COUNT_WAIT_MS ? this.digits + key : key;
      this.lastAt = at;
      return null;
    }
    if (TIMES.has(key) && this.digits !== '' && at - this.lastAt < SCAN_COUNT_WAIT_MS) {
      const count = Number(this.digits);
      this.reset();
      return count >= 1 && count <= SCAN_COUNT_MAX ? count : null;
    }
    // A named key (Shift for the digits of an AZERTY row) neither joins nor breaks the count.
    if ([...key].length === 1) this.reset();
    return null;
  }

  reset(): void {
    this.digits = '';
    this.lastAt = Number.NEGATIVE_INFINITY;
  }
}
