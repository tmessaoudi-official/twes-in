// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  afterNextRender,
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  ElementRef,
  inject,
  Injector,
  input,
} from '@angular/core';
import { MatTabsModule } from '@angular/material/tabs';
import { type IsActiveMatchOptions, RouterLink, RouterLinkActive } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';

export interface PageTab {
  readonly labelKey: string;
  readonly route: string;
  readonly testId: string;
}

/**
 * The screens of one feature as tabs, such as customers and their groups, wrapped around the page they show: each tab
 * is a link to its own address, so the sidebar keeps a single entry per feature, and the page is the tabs' panel.
 */
@Component({
  selector: 'app-page-tabs',
  imports: [MatTabsModule, RouterLink, RouterLinkActive, TranslatePipe],
  template: `
    <!-- A native sideways scroll rather than Material's chevrons: it swipes on a phone, and the open tab is kept in
         view whenever the labels change size (translated after the first layout, they pushed it off). -->
    <nav
      mat-tab-nav-bar
      class="twes-page-tabs"
      [disablePagination]="true"
      [tabPanel]="panel"
      [attr.aria-label]="label() | translate"
    >
      @for (tab of tabs(); track tab.route) {
        <a
          mat-tab-link
          [routerLink]="tab.route"
          routerLinkActive
          #active="routerLinkActive"
          [routerLinkActiveOptions]="matching"
          [active]="selected() === null ? active.isActive : selected() === tab.route"
          [attr.data-testid]="tab.testId"
          (isActiveChange)="revealLater()"
        >
          {{ tab.labelKey | translate }}
        </a>
      }
    </nav>
    <mat-tab-nav-panel #panel class="mt-6 block">
      <ng-content />
    </mat-tab-nav-panel>
  `,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PageTabs {
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;
  private readonly injector = inject(Injector);
  readonly tabs = input.required<readonly PageTab[]>();
  /** The translation key naming the tabs' navigation. */
  readonly label = input.required<string>();
  /**
   * The route of the tab a page is, when it opens at an address of its own as well (count mode at a location's QR
   * code); left out, the tab whose address is the page's is selected.
   */
  readonly selected = input<string | null>(null);

  /**
   * Each tab is its own path exactly, and only its path: what a page keeps after it — a mode, a search, a fragment —
   * is that page's state, and `exact: true` would match the query too, leaving the open tab unmarked.
   */
  protected readonly matching: IsActiveMatchOptions = {
    paths: 'exact',
    queryParams: 'ignored',
    fragment: 'ignored',
    matrixParams: 'ignored',
  };

  constructor() {
    const destroyRef = inject(DestroyRef);
    afterNextRender(() => {
      this.reveal();
      if (typeof ResizeObserver === 'undefined') return;
      const observer = new ResizeObserver(() => this.reveal());
      for (const link of this.host.querySelectorAll('nav a')) observer.observe(link);
      destroyRef.onDestroy(() => observer.disconnect());
    });
  }

  /** After the tab just chosen is drawn as selected. */
  protected revealLater(): void {
    afterNextRender(() => this.reveal(), { injector: this.injector });
  }

  /** `scrollIntoView` is optional because jsdom has none, as in the menu's scroller. */
  private reveal(): void {
    this.host
      .querySelector<HTMLElement>('nav a[aria-selected="true"]')
      ?.scrollIntoView?.({ block: 'nearest', inline: 'nearest' });
  }
}
