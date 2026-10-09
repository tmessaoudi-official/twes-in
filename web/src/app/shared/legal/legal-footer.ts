// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  afterNextRender,
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  ElementRef,
  inject,
  viewChild,
} from '@angular/core';
import { TranslatePipe } from '@ngx-translate/core';
import { Brand } from '../brand/brand';
import { BuildLine } from '../build/build-line';
import { LegalLink } from './legal-link';
import { LEGAL_PAGES } from './legal-pages';

/**
 * The copyright and legal-links line: slim and centred, under the content of every page, signed out too, scrolling
 * with it. The brand is the installation's own, through the `Brand` port. The licence leads to the source page, which
 * a network service under the AGPL owes its users (§ 13). Each link opens its text over the screen (`LegalLink`). The
 * builds that answer (`BuildLine`) stand on a line of their own under it. Each dot goes with the link after it, so a
 * line that wraps never ends on a dot left hanging, and the dot of a link that opens a line is not drawn.
 */
@Component({
  selector: 'app-legal-footer',
  imports: [BuildLine, LegalLink, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <nav
      class="twes-legal-line"
      [attr.aria-label]="'legal.navigation' | translate"
      data-testid="legal-footer"
    >
      <span class="twes-legal-links" #links>
        <span data-testid="legal-copyright">© {{ year }} {{ brand.name() }}</span>
        <!-- The dots are drawn, not said: a screen reader reads each link by its own name. -->
        <span class="twes-legal-item"
          ><span aria-hidden="true">·</span>
          <a appLegalLink="source" data-testid="legal-licence">AGPL-3.0</a></span
        >
        @for (slug of pages; track slug) {
          <span class="twes-legal-item"
            ><span aria-hidden="true">·</span>
            <a [appLegalLink]="slug" [attr.data-testid]="'legal-link-' + slug">{{
              'legal.pages.' + slug | translate
            }}</a></span
          >
        }
      </span>
      <app-build-line />
    </nav>
  `,
})
export class LegalFooter {
  protected readonly brand = inject(Brand);
  protected readonly year = new Date().getFullYear();
  protected readonly pages = LEGAL_PAGES;
  private readonly links = viewChild.required<ElementRef<HTMLElement>>('links');

  constructor() {
    const destroyRef = inject(DestroyRef);
    // Where the line breaks moves with the window's width, the language and the font arriving: each item is watched.
    afterNextRender(() => {
      const links = this.links().nativeElement;
      const mark = (): void => markLineStarts(links);
      // jsdom has no ResizeObserver, and no layout to break a line in either.
      if (typeof ResizeObserver === 'undefined') return;
      const observer = new ResizeObserver(mark);
      observer.observe(links);
      for (const item of Array.from(links.children)) observer.observe(item);
      void document.fonts?.ready.then(mark);
      destroyRef.onDestroy(() => observer.disconnect());
    });
  }
}

/**
 * Marks each link that opens a line, whose dot is then not drawn. Hidden, not removed: the dot keeps its width, so
 * marking an item never moves where the line breaks.
 */
export function markLineStarts(links: HTMLElement): void {
  let previousTop: number | null = null;
  for (const item of Array.from(links.children) as HTMLElement[]) {
    const top = item.offsetTop;
    if (item.classList.contains('twes-legal-item')) {
      item.classList.toggle('twes-legal-item-first', previousTop !== null && top > previousTop + 1);
    }
    previousTop = top;
  }
}
