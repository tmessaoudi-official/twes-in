// SPDX-License-Identifier: AGPL-3.0-or-later

import { DOCUMENT, inject, InjectionToken } from '@angular/core';
import { PageMemoryStorage, type SettingsStorage } from '../shared/settings/settings-facade';

/**
 * Where the browser remembers which establishment its customer screen stands at, per company: kept on the device,
 * because the screen is a device in a shop and stands where the shop is, whoever opens it.
 */
export const CUSTOMER_SCREEN_PLACE_STORAGE = new InjectionToken<SettingsStorage>(
  'CUSTOMER_SCREEN_PLACE_STORAGE',
  {
    providedIn: 'root',
    factory: () => {
      try {
        // Reading the property itself throws when site data is blocked.
        return inject(DOCUMENT).defaultView?.localStorage ?? new PageMemoryStorage();
      } catch {
        return new PageMemoryStorage();
      }
    },
  },
);

export const CUSTOMER_SCREEN_PLACE_KEY = 'twes.customer-screen.place';

/** The establishment remembered for the company, if any. */
export function rememberedPlace(storage: SettingsStorage, companyId: string): string | null {
  const held = readPlaces(storage);
  const id = held[companyId];
  return typeof id === 'string' ? id : null;
}

/** Remembers the establishment for the company, leaving the other companies' choices as they were. */
export function rememberPlace(storage: SettingsStorage, companyId: string, placeId: string): void {
  try {
    storage.setItem(
      CUSTOMER_SCREEN_PLACE_KEY,
      JSON.stringify({ ...readPlaces(storage), [companyId]: placeId }),
    );
  } catch {
    // Storage refused (site data blocked or full): the choice holds for this page only, and is asked again next time.
  }
}

/** Forgets the company's establishment, leaving the other companies' choices as they were. */
export function forgetPlace(storage: SettingsStorage, companyId: string): void {
  const rest = Object.fromEntries(
    Object.entries(readPlaces(storage)).filter(([id]) => id !== companyId),
  );
  try {
    storage.setItem(CUSTOMER_SCREEN_PLACE_KEY, JSON.stringify(rest));
  } catch {
    // Storage refused: nothing was kept there to forget.
  }
}

function readPlaces(storage: SettingsStorage): Record<string, unknown> {
  try {
    const parsed: unknown = JSON.parse(storage.getItem(CUSTOMER_SCREEN_PLACE_KEY) ?? '{}');
    return parsed !== null && typeof parsed === 'object' && !Array.isArray(parsed)
      ? (parsed as Record<string, unknown>)
      : {};
  } catch {
    return {};
  }
}
