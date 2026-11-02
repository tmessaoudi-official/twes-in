// SPDX-License-Identifier: AGPL-3.0-or-later

import { DestroyRef, Directive, ElementRef, inject } from '@angular/core';
import { DOCUMENT } from '@angular/common';

/** The stuck document header's height on the root: the page's scroll-padding and a sticky summary keep clear of it. */
export const STICKY_HEADER_BLOCK_SIZE = '--twes-sticky-header-block-size';

/** The header that said it last: the one leaving takes back only what it said itself. */
let speaking: object | null = null;

/**
 * A document's header stays in view under the shell's bar while the page scrolls (`.twes-document-header`). A field
 * reached by Tab, or the summary stuck below it, must keep clear of it (WCAG 2.4.11, RGAA 10.7), which takes its
 * height: its labels, the language and the text size decide it, so it is measured. It is set on every element carrying
 * `twes-document-header` (record-pages.spec.ts holds the class).
 */
@Directive({ selector: '[appStickyHeader]' })
export class StickyHeaderSize {
  constructor() {
    const header = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;
    const root = inject(DOCUMENT).documentElement;
    // jsdom, where the component specs run, has no ResizeObserver; a browser always has one.
    if (typeof ResizeObserver === 'undefined') return;
    const self = {};
    const observer = new ResizeObserver(() => {
      speaking = self;
      root.style.setProperty(STICKY_HEADER_BLOCK_SIZE, `${header.offsetHeight}px`);
    });
    observer.observe(header);
    inject(DestroyRef).onDestroy(() => {
      observer.disconnect();
      if (speaking !== self) return;
      speaking = null;
      root.style.removeProperty(STICKY_HEADER_BLOCK_SIZE);
    });
  }
}
