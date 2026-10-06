// SPDX-License-Identifier: AGPL-3.0-or-later

import { afterEveryRender, Directive, ElementRef, inject, signal } from '@angular/core';

/**
 * A menu's scroll box (docs/SPEC.md § 7, 2026-09-26 12:05, row 152), measured at 1280×800: five destinations sat below
 * the fold with no sign they were there, and the folded rail hides even its scrollbar. So each edge with more behind it
 * says so (`data-more-above`, `data-more-below`, which `styles.scss` draws as a fade), and the entry of the page on view
 * (`aria-current="page"`) is brought into view whenever it is another entry: after a navigation, and when the menu
 * draws it late, as the module entries that arrive with the signed-in state are, after the first navigation ended.
 */
@Directive({
  selector: '[appNavScroller]',
  host: {
    '(scroll)': 'measure()',
    '[attr.data-more-above]': 'above() ? "" : null',
    '[attr.data-more-below]': 'below() ? "" : null',
  },
})
export class NavScroller {
  private readonly element: HTMLElement = inject(ElementRef).nativeElement;
  protected readonly above = signal(false);
  protected readonly below = signal(false);

  /** The entry last brought into view, so a render that keeps it leaves the menu where the person scrolled it. */
  private revealed: Element | null = null;
  /** The menu's layout and scroll position just after that, to bring it back when the layout moves unscrolled. */
  private revealedLayout = '';
  private revealedTop = 0;

  constructor() {
    // Folding a section or a new window size changes what is behind an edge without any scroll.
    afterEveryRender({ write: () => this.revealCurrent(), read: () => this.measure() });
  }

  protected measure(): void {
    const { scrollTop, clientHeight, scrollHeight } = this.element;
    // A pixel of slack: fractional layouts never land exactly on the end.
    this.above.set(scrollTop > 1);
    this.below.set(scrollTop + clientHeight < scrollHeight - 1);
  }

  private layout(): string {
    return `${this.element.scrollHeight}/${this.element.clientHeight}`;
  }

  /**
   * `scrollIntoView` is optional because jsdom has none, as in the command palette. The same entry is brought back
   * when the menu's layout moved while nobody scrolled it: what arrives late around it (a module's entries above, the
   * account block below, which shortens the box) pushes it back under the fade.
   */
  private revealCurrent(): void {
    const current = this.element.querySelector('[aria-current="page"]');
    if (current === null) return;
    const settledAgain =
      this.layout() !== this.revealedLayout && this.element.scrollTop === this.revealedTop;
    if (current === this.revealed && !settledAgain) return;
    this.revealed = current;
    current.scrollIntoView?.({ block: 'nearest' });
    this.revealedLayout = this.layout();
    this.revealedTop = this.element.scrollTop;
  }
}
