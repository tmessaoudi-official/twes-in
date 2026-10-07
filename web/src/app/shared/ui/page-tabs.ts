// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { MatTabsModule } from '@angular/material/tabs';
import { RouterLink, RouterLinkActive } from '@angular/router';
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
    <nav mat-tab-nav-bar [tabPanel]="panel" [attr.aria-label]="label() | translate">
      @for (tab of tabs(); track tab.route) {
        <a
          mat-tab-link
          [routerLink]="tab.route"
          routerLinkActive
          #active="routerLinkActive"
          [routerLinkActiveOptions]="{ exact: true }"
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
  readonly tabs = input.required<readonly PageTab[]>();
  /** The translation key naming the tabs' navigation. */
  readonly label = input.required<string>();
  /**
   * The route of the tab a page is, when it opens at an address of its own as well (count mode at a location's QR
   * code); left out, the tab whose address is the page's is selected.
   */
  readonly selected = input<string | null>(null);
}
