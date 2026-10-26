// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { MatTabsModule } from '@angular/material/tabs';
import { type IsActiveMatchOptions, RouterLink, RouterLinkActive } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { Session } from '../session/session';
import { SelectedTabInView } from './selected-tab-in-view';

export interface PageTab {
  readonly labelKey: string;
  readonly route: string;
  readonly testId: string;
  /** What its page needs beyond the feature's own read, such as counting the stock: absent, the tab is everyone's. */
  readonly permission?: string;
}

/**
 * The screens of one feature as tabs, such as customers and their groups, wrapped around the page they show: each tab
 * is a link to its own address, so the sidebar keeps a single entry per feature, and the page is the tabs' panel.
 */
@Component({
  selector: 'app-page-tabs',
  imports: [MatTabsModule, RouterLink, RouterLinkActive, SelectedTabInView, TranslatePipe],
  template: `
    <!-- A native sideways scroll rather than Material's chevrons: it swipes on a phone, and the open tab is kept in
         view whenever the labels change size (translated after the first layout, they pushed it off). -->
    <nav
      mat-tab-nav-bar
      appSelectedTabInView
      class="twes-page-tabs"
      [disablePagination]="true"
      [tabPanel]="panel"
      [attr.aria-label]="label() | translate"
    >
      @for (tab of shown(); track tab.route) {
        <a
          mat-tab-link
          [routerLink]="tab.route"
          routerLinkActive
          #active="routerLinkActive"
          [routerLinkActiveOptions]="matching"
          [active]="selected() === null ? active.isActive : selected() === tab.route"
          [attr.data-testid]="tab.testId"
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
  private readonly session = inject(Session);

  readonly tabs = input.required<readonly PageTab[]>();
  /** The tabs whose page the person may open, as the sidebar shows only the entries a role may reach. */
  protected readonly shown = computed(() =>
    this.tabs().filter(
      (tab) => tab.permission === undefined || this.session.hasPermission(tab.permission),
    ),
  );
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
}
