// SPDX-License-Identifier: AGPL-3.0-or-later

import { Injectable, signal } from '@angular/core';

/**
 * Whether a screen has taken the whole window, as the stock map does in full screen. The shell reads it to lift the
 * page above its own rail and bars and to take those out of the keyboard's way: a page cannot do either from inside
 * the shell's content, which is a layer of its own beneath the rail.
 */
@Injectable({ providedIn: 'root' })
export class Immersive {
  private readonly taken = signal(false);
  readonly on = this.taken.asReadonly();

  set(on: boolean): void {
    this.taken.set(on);
  }
}
