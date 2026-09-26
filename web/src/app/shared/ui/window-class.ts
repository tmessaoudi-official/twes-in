// SPDX-License-Identifier: AGPL-3.0-or-later

import { BreakpointObserver } from '@angular/cdk/layout';
import { inject, InjectionToken, type Signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { map } from 'rxjs';

/**
 * How wide the window is, in Material's window size classes. The shell lays itself out by it — a bottom bar on a
 * phone, a rail, a labelled drawer — and a list uses the same boundary to stop being a table, so the two cannot
 * disagree about where a phone ends.
 */
export type WindowClass = 'compact' | 'medium' | 'expanded';

export const COMPACT_WINDOW = '(max-width: 599.98px)';
export const EXPANDED_WINDOW = '(min-width: 1200px)';
/**
 * Where the settings area puts its list beside the page rather than taking turns with it: Tailwind's `lg`, which its
 * template's `lg:` classes use. `]` folds that list only from here (row 151).
 */
export const SETTINGS_BESIDE_WINDOW = '(min-width: 1024px)';

/** The one window class, so a spec can say how wide the window is without a media query. */
export const WINDOW_CLASS = new InjectionToken<Signal<WindowClass>>('WINDOW_CLASS', {
  providedIn: 'root',
  factory: () =>
    toSignal(
      inject(BreakpointObserver)
        .observe([COMPACT_WINDOW, EXPANDED_WINDOW])
        .pipe(
          map((state): WindowClass =>
            state.breakpoints[COMPACT_WINDOW]
              ? 'compact'
              : state.breakpoints[EXPANDED_WINDOW]
                ? 'expanded'
                : 'medium',
          ),
        ),
      { initialValue: 'expanded' as WindowClass },
    ),
});
