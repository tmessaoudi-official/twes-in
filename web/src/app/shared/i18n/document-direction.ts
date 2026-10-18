// SPDX-License-Identifier: AGPL-3.0-or-later

import { type Direction, Directionality } from '@angular/cdk/bidi';
import { DestroyRef, DOCUMENT, inject, Injectable } from '@angular/core';

/**
 * Keeps the components' direction (the CDK's root `Directionality`, which overlays, menus and drawers read) on the
 * document's `dir`. The CDK reads that attribute once, when it starts; the language facade moves it on every switch,
 * so this follows it, the attribute staying the one place the direction is said.
 */
@Injectable({ providedIn: 'root' })
export class DocumentDirection {
  private readonly direction = inject(Directionality);
  private readonly root = inject(DOCUMENT).documentElement;

  constructor() {
    this.follow();
    const watch = new MutationObserver(() => this.follow());
    watch.observe(this.root, { attributes: true, attributeFilter: ['dir'] });
    inject(DestroyRef).onDestroy(() => watch.disconnect());
  }

  private follow(): void {
    const value: Direction = this.root.dir === 'rtl' ? 'rtl' : 'ltr';
    if (this.direction.value === value) return;
    this.direction.valueSignal.set(value);
    this.direction.change.emit(value);
  }
}
