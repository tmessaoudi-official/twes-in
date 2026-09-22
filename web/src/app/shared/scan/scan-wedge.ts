// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * How close two keystrokes must be to come from a scanner. A hand leaves tens of milliseconds between two characters;
 * a wedge scanner, which types what it read as a keyboard would, leaves one or two.
 */
export const SCAN_GAP_MS = 30;

/** The shortest code read as a scan: below it a quick double press is likelier than a barcode. */
export const SCAN_MIN_LENGTH = 4;

/** One keystroke, as much of it as the reading needs. */
export interface WedgeKey {
  readonly key: string;
  /** When it arrived, in milliseconds. */
  readonly at: number;
  /** Whether it landed where a person writes: there the scan is the field's, never the page's. */
  readonly editable: boolean;
  /** Ctrl, Alt or Meta held: a command, never part of a code. Shift is how a scanner types a capital. */
  readonly modified: boolean;
}

export interface WedgeReading {
  /** The code a scanner just finished with its Enter, or null. */
  readonly code: string | null;
  /**
   * Whether the key is part of a burst, so what a screen binds to it must not run. The first character of a scan
   * cannot be told from a hand's until the second arrives, so it is never claimed.
   */
  readonly claimed: boolean;
}

/**
 * Tells a scan from typing on a page with no field focused (docs/SPEC.md § 7, 2026-09-23 01:10): a burst of
 * characters each within `SCAN_GAP_MS` of the one before, closed by an Enter as quick. Fed every keydown; answers the
 * code once the Enter closes a burst.
 */
export class ScanWedge {
  private buffer = '';
  private lastAt = Number.NEGATIVE_INFINITY;

  read(key: WedgeKey): WedgeReading {
    if (key.editable || key.modified) {
      this.reset();
      return { code: null, claimed: false };
    }
    if (key.key === 'Enter') {
      const code =
        this.buffer.length >= SCAN_MIN_LENGTH && key.at - this.lastAt < SCAN_GAP_MS
          ? this.buffer
          : null;
      this.reset();
      return { code, claimed: code !== null };
    }
    // A named key (Shift, CapsLock) is not a character: it neither joins nor breaks a burst.
    if ([...key.key].length !== 1) return { code: null, claimed: false };

    const inBurst = this.buffer !== '' && key.at - this.lastAt < SCAN_GAP_MS;
    this.buffer = inBurst ? this.buffer + key.key : key.key;
    this.lastAt = key.at;
    return { code: null, claimed: inBurst };
  }

  private reset(): void {
    this.buffer = '';
    this.lastAt = Number.NEGATIVE_INFINITY;
  }
}
