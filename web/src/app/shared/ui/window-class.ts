// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, InjectionToken, type Signal } from '@angular/core';

import { TextFactor, type WindowClass } from './text-factor';

export { TextFactor, type WindowClass, windowClassOf } from './text-factor';

/**
 * Where the settings area puts its list beside the page rather than taking turns with it: Tailwind's `lg`, which its
 * template's `lg:` classes use. `]` folds that list only from here (row 151). Kept in CSS pixels, as Tailwind's own
 * breakpoints are, so the two never disagree.
 */
export const SETTINGS_BESIDE_WINDOW = '(min-width: 1024px)';

/**
 * The one window class, so a spec can say how wide the window is without a media query. Decided on effective pixels,
 * the window over the text factor (TextFactor), the same reading the root's `data-window` takes for the stylesheet's
 * phone rules: no media query follows the root's font size, so none is asked.
 */
export const WINDOW_CLASS = new InjectionToken<Signal<WindowClass>>('WINDOW_CLASS', {
  providedIn: 'root',
  factory: () => inject(TextFactor).windowClass,
});
