// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { SETTINGS_STORAGE } from '../settings/settings-facade';
import { SCAN_MIN_LENGTH } from './scan-wedge';

/** Where this browser keeps the shortest code it reads as a scan from a page with no field focused. */
export const SCAN_MIN_LENGTH_KEY = 'twes.scan.min-length';

/**
 * The lengths this browser may choose: one character would take a quick key and Enter for a scan, and above eight an
 * EAN-8 barcode would no longer read.
 */
export const SCAN_MIN_LENGTH_CHOICES: readonly number[] = [2, 3, 4, 5, 6, 7, 8];

function offered(value: number): boolean {
  return SCAN_MIN_LENGTH_CHOICES.includes(value);
}

/**
 * The shortest burst the page reads as a scan: a setting of this browser beside the scanner's gap, since the codes a
 * shop scans at this computer set it. Four characters, as before, until someone here chooses otherwise: a shop whose
 * references are two or three characters long scans them from the page once it says so. A field reads any length.
 */
@Injectable({ providedIn: 'root' })
export class ScanMinLength {
  private readonly storage = inject(SETTINGS_STORAGE);
  private readonly value = signal(this.remembered());

  readonly length = this.value.asReadonly();

  /** Keeps a length the browser may choose; any other is not taken. */
  set(length: number): void {
    if (!offered(length)) return;
    this.value.set(length);
    try {
      this.storage.setItem(SCAN_MIN_LENGTH_KEY, String(length));
    } catch {
      // A browser refusing storage keeps the choice for this page only.
    }
  }

  reset(): void {
    this.value.set(SCAN_MIN_LENGTH);
    try {
      this.storage.removeItem(SCAN_MIN_LENGTH_KEY);
    } catch {
      // Nothing was kept, or nothing can be: the default holds for this page either way.
    }
  }

  private remembered(): number {
    try {
      const stored = Number(this.storage.getItem(SCAN_MIN_LENGTH_KEY));
      return offered(stored) ? stored : SCAN_MIN_LENGTH;
    } catch {
      return SCAN_MIN_LENGTH;
    }
  }
}
