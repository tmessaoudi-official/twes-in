// SPDX-License-Identifier: AGPL-3.0-or-later

import { DOCUMENT, inject, InjectionToken } from '@angular/core';
import { PageMemoryStorage, type SettingsStorage } from '../settings/settings-facade';

/**
 * How large the screens' text is on this device, in percent of the browser's own size. It belongs to the device, not
 * the person (the developer's ruling on the design decisions): a counter's screen read from a step away and the same
 * person's phone want different sizes. So it is kept in this browser, never sent to the API, and applied before the
 * first paint by the boot script in index.html, which reads the same key.
 */
export const TEXT_SIZES = ['100', '112', '125'] as const;
export type TextSize = (typeof TEXT_SIZES)[number];

export const DEFAULT_TEXT_SIZE: TextSize = '100';
export const TEXT_SIZE_KEY = 'twes.text-size';

/**
 * The root class each size puts on <html>; styles.scss gives it its font size (112.5 % so a rem lands on whole
 * pixels). index.html builds the same names from the key's value.
 */
export const TEXT_SIZE_CLASSES: Readonly<Record<TextSize, string | null>> = {
  '100': null,
  '112': 'twes-text-112',
  '125': 'twes-text-125',
};

/** The window that faces the customer keeps its own size, whatever the counter's person reads at. */
const CUSTOMER_DISPLAY = /^\/customer-display(?:[/?#]|$)/;

export function textSizeApplies(pathname: string): boolean {
  return !CUSTOMER_DISPLAY.test(pathname);
}

/** This device's storage, where the text size is kept whoever signs in on it. */
export const TEXT_SIZE_STORAGE = new InjectionToken<SettingsStorage>('TEXT_SIZE_STORAGE', {
  providedIn: 'root',
  factory: () => {
    try {
      // Reading the property itself throws when site data is blocked.
      return inject(DOCUMENT).defaultView?.localStorage ?? new PageMemoryStorage();
    } catch {
      return new PageMemoryStorage();
    }
  },
});

export function isTextSize(value: unknown): value is TextSize {
  return (TEXT_SIZES as readonly unknown[]).includes(value);
}

export function storedTextSize(storage: SettingsStorage): TextSize {
  try {
    const kept = storage.getItem(TEXT_SIZE_KEY);
    return isTextSize(kept) ? kept : DEFAULT_TEXT_SIZE;
  } catch {
    // Storage refused (site data blocked): the browser's own size.
    return DEFAULT_TEXT_SIZE;
  }
}

export function keepTextSize(storage: SettingsStorage, size: TextSize): void {
  try {
    storage.setItem(TEXT_SIZE_KEY, size);
  } catch {
    // Storage refused (site data blocked or full): the size holds for this page, and the next one opens at 100 %.
  }
}
