// SPDX-License-Identifier: AGPL-3.0-or-later

import { BreakpointObserver } from '@angular/cdk/layout';
import { inject, InjectionToken, type Signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { map } from 'rxjs';

/** A finger rather than a mouse: what a target must be sized for, which the window's width does not say. */
export const COARSE_POINTER_QUERY = '(pointer: coarse)';

/** Whether the main pointer is a finger, so a spec can say so without a media query. */
export const COARSE_POINTER = new InjectionToken<Signal<boolean>>('COARSE_POINTER', {
  providedIn: 'root',
  factory: () =>
    toSignal(
      inject(BreakpointObserver)
        .observe(COARSE_POINTER_QUERY)
        .pipe(map((state) => state.matches)),
      { initialValue: false },
    ),
});
