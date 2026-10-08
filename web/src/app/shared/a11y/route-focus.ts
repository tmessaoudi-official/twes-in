// SPDX-License-Identifier: AGPL-3.0-or-later

import { afterNextRender, Directive, ElementRef, inject, Injector } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { NavigationEnd, Router } from '@angular/router';
import { filter, map, pairwise } from 'rxjs';

/**
 * Put on the main region: after a navigation to another page, focus moves to that page's heading, or to the region
 * when the page has none, so a keyboard or screen-reader user starts reading the new page rather than wherever the
 * old one left them (RGAA 12.7). The first page is left alone, as the browser opened it, and so is a change of query
 * only — a list's next page or filter — which would otherwise throw focus away from the control just used.
 */
@Directive({ selector: '[appRouteFocus]' })
export class RouteFocus {
  constructor() {
    const host = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;
    const injector = inject(Injector);
    inject(Router)
      .events.pipe(
        filter((event): event is NavigationEnd => event instanceof NavigationEnd),
        map((event) => event.urlAfterRedirects.split(/[?#]/)[0]),
        pairwise(),
        filter(([before, after]) => before !== after),
        takeUntilDestroyed(),
      )
      .subscribe(() =>
        afterNextRender(
          () => {
            const heading = host.querySelector<HTMLElement>('h1');
            if (heading === null) {
              host.focus();
              return;
            }
            if (!heading.hasAttribute('tabindex')) heading.setAttribute('tabindex', '-1');
            heading.focus();
          },
          { injector },
        ),
      );
  }
}
