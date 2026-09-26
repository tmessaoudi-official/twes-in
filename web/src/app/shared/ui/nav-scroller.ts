// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  afterEveryRender,
  afterNextRender,
  DestroyRef,
  Directive,
  ElementRef,
  inject,
  Injector,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { NavigationEnd, Router } from '@angular/router';
import { filter } from 'rxjs';

/**
 * A menu's scroll box (docs/SPEC.md § 7, 2026-09-26 12:05, row 152), measured at 1280×800: five destinations sat below
 * the fold with no sign they were there, and the folded rail hides even its scrollbar. So each edge with more behind it
 * says so (`data-more-above`, `data-more-below`, which `styles.scss` draws as a fade), and after each navigation the
 * entry of the page on view (`aria-current="page"`) is brought into view.
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

  constructor() {
    const injector = inject(Injector);
    // Folding a section or a new window size changes what is behind an edge without any scroll.
    afterEveryRender({ read: () => this.measure() });
    afterNextRender(() => this.revealCurrent());
    inject(Router)
      .events.pipe(
        filter((event) => event instanceof NavigationEnd),
        takeUntilDestroyed(inject(DestroyRef)),
      )
      .subscribe(() => afterNextRender(() => this.revealCurrent(), { injector }));
  }

  protected measure(): void {
    const { scrollTop, clientHeight, scrollHeight } = this.element;
    // A pixel of slack: fractional layouts never land exactly on the end.
    this.above.set(scrollTop > 1);
    this.below.set(scrollTop + clientHeight < scrollHeight - 1);
  }

  /** `scrollIntoView` is optional because jsdom has none, as in the command palette. */
  private revealCurrent(): void {
    this.element.querySelector('[aria-current="page"]')?.scrollIntoView?.({ block: 'nearest' });
  }
}
