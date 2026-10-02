// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { SETTINGS_STORAGE } from '../settings/settings-facade';

/** Where this browser keeps how close two keystrokes must be to come from its scanner. */
export const SCAN_GAP_KEY = 'twes.scan.gap';

/** What a wedge scanner is told from a hand by until this browser measures its own. */
export const SCAN_GAP_DEFAULT_MS = 30;

/** The widest and narrowest gap that still tells a scanner from a hand: below or above, one passes for the other. */
const GAP_MIN_MS = 10;
const GAP_MAX_MS = 100;

/** A key pressed this long after the one before is a hand's, whatever the scanner. */
const HAND_MS = 100;

function within(value: number): boolean {
  return Number.isInteger(value) && value >= GAP_MIN_MS && value <= GAP_MAX_MS;
}

/**
 * How close two keystrokes must be to count as one scanner's burst (docs/SPEC.md § 7, 2026-09-24 12:40 row 13): a
 * setting of this browser, since it is the scanner plugged into this machine that sets it. Thirty milliseconds suits
 * most wedge scanners; a slow one, or a link that delivers keys late, needs more, which `suggestGap` measures.
 */
@Injectable({ providedIn: 'root' })
export class ScanGap {
  private readonly storage = inject(SETTINGS_STORAGE);
  private readonly value = signal(this.remembered());

  readonly gap = this.value.asReadonly();

  /** Keeps a gap inside the range; a value outside it is not taken. */
  set(ms: number): void {
    if (!within(ms)) return;
    this.value.set(ms);
    try {
      this.storage.setItem(SCAN_GAP_KEY, String(ms));
    } catch {
      // A browser refusing storage keeps the choice for this page only.
    }
  }

  reset(): void {
    this.value.set(SCAN_GAP_DEFAULT_MS);
    try {
      this.storage.removeItem(SCAN_GAP_KEY);
    } catch {
      // Nothing was kept, or nothing can be: the default holds for this page either way.
    }
  }

  private remembered(): number {
    try {
      const stored = Number(this.storage.getItem(SCAN_GAP_KEY));
      return within(stored) ? stored : SCAN_GAP_DEFAULT_MS;
    } catch {
      return SCAN_GAP_DEFAULT_MS;
    }
  }
}

/**
 * The gap to set from the moments a scanner's keys arrived, in milliseconds: three times the slowest interval of the
 * burst, up to the next five, kept between twenty and sixty. Nothing from fewer than five keys, which says too little,
 * or from keys a hand typed, which says nothing about a scanner.
 */
export function suggestGap(times: readonly number[]): number | null {
  if (times.length < 5) return null;
  const intervals = times.slice(1).map((time, index) => time - (times[index] ?? 0));
  const slowest = Math.max(...intervals);
  if (slowest >= HAND_MS || intervals.some((each) => each < 0)) return null;
  return Math.min(60, Math.max(20, Math.ceil((slowest * 3) / 5) * 5));
}
