// SPDX-License-Identifier: AGPL-3.0-or-later

import { InjectionToken } from '@angular/core';

/**
 * What a phone is told about a code it read beyond the sentence and the price: a few short lines a customer at the
 * till could read off the screen — stock, the nearest use-by date, what a pack holds. `shared/` imports no feature, so
 * the product feature provides this; without it a phone hears the sentence and the price alone.
 */
export interface ScanDetails {
  /** Lines in the person's language, at most a handful; a lookup that fails gives none rather than failing the scan. */
  of(code: string): Promise<readonly string[]>;
}

export const SCAN_DETAILS = new InjectionToken<ScanDetails>('SCAN_DETAILS', {
  providedIn: 'root',
  factory: () => ({ of: async () => [] }),
});
