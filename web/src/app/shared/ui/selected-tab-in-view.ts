// SPDX-License-Identifier: AGPL-3.0-or-later

import { afterNextRender, DestroyRef, Directive, ElementRef, inject } from '@angular/core';
import { revealAlongLine } from '../i18n/inline-direction';

/**
 * Keeps the open tab of a sideways-scrolling tab bar in view: when the page opens on it, when another is chosen, and
 * whenever the labels change size (translated after the first layout, they pushed it off). Placed on the
 * `mat-tab-nav-bar` itself, beside `twes-page-tabs` and `disablePagination`.
 */
@Directive({ selector: '[appSelectedTabInView]' })
export class SelectedTabInView {
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;

  constructor() {
    const destroyRef = inject(DestroyRef);
    afterNextRender(() => {
      this.reveal();
      const chosen = new MutationObserver(() => this.reveal());
      chosen.observe(this.host, {
        subtree: true,
        attributes: true,
        attributeFilter: ['aria-selected'],
      });
      destroyRef.onDestroy(() => chosen.disconnect());
      if (typeof ResizeObserver === 'undefined') return;
      const resized = new ResizeObserver(() => this.reveal());
      for (const link of this.host.querySelectorAll('a')) resized.observe(link);
      destroyRef.onDestroy(() => resized.disconnect());
    });
  }

  /** The bar's own box scrolls, never the page: the tab bar's labels change size wherever the page is scrolled. */
  private reveal(): void {
    const open = this.host.querySelector<HTMLElement>('a[aria-selected="true"]');
    const bar = this.host.querySelector<HTMLElement>('.mat-mdc-tab-link-container');
    if (open !== null && bar !== null) revealAlongLine(bar, open);
  }
}
