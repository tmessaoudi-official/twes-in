// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDividerModule } from '@angular/material/divider';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { Router } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { Label } from '../a11y/label';
import { Session } from '../session/session';
import { ThemeFacade } from '../theme/theme-facade';
import { WINDOW_CLASS } from '../ui/window-class';

/** Something a screen will offer once a planned module ships: shown « Bientôt », never run. */
export interface PlannedAction {
  /** The planned module's key in the API's catalogue; the action is drawn only while the catalogue lists it. */
  readonly module: string;
  /** A translation key. */
  readonly label: string;
  /** A Material Symbols ligature. */
  readonly icon: string;
}

/**
 * The actions of a screen not built yet (docs/SPEC.md § 7, 2026-09-26 10:08 and 18:17, row 150): a group of their own
 * before the working ones, dashed and muted, each marked « Bientôt » and opening its module's « En construction »
 * page. They are not `ScreenAction`s: no key, no E, no line in the "?" sheet or the palette, which already leads to
 * each planned module. On a phone they fold into their own « ⋯ ».
 *
 * A module that ships leaves the catalogue's planned list, and its actions vanish here with it: the screen then
 * declares the real ones.
 */
@Component({
  selector: 'app-planned-actions',
  imports: [MatButtonModule, MatDividerModule, MatIconModule, MatMenuModule, TranslatePipe, Label],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'contents' },
  template: `
    @if (offered().length > 0) {
      <div
        class="flex flex-wrap items-center gap-2"
        role="group"
        [attr.aria-label]="'actions.coming' | translate"
        data-testid="planned-actions"
      >
        @if (compact()) {
          <button
            mat-icon-button
            type="button"
            [matMenuTriggerFor]="plannedMenu"
            [appLabel]="'actions.coming_more' | translate"
            data-testid="planned-more"
          >
            <mat-icon aria-hidden="true">more_horiz</mat-icon>
          </button>
          <mat-menu #plannedMenu="matMenu" xPosition="before">
            @for (action of offered(); track action.module) {
              <button
                mat-menu-item
                type="button"
                (click)="open(action)"
                [attr.data-testid]="'planned-menu-' + action.module"
              >
                <mat-icon aria-hidden="true">{{ action.icon }}</mat-icon>
                <span class="twes-rail-title">
                  <span>{{ action.label | translate }}</span>
                  <span class="twes-soon" data-testid="soon">{{ 'shell.soon' | translate }}</span>
                </span>
              </button>
            }
          </mat-menu>
        } @else {
          @for (action of offered(); track action.module) {
            <button
              mat-stroked-button
              type="button"
              class="twes-planned-action"
              [aria-disabled]="true"
              (click)="open(action)"
              [attr.data-testid]="'planned-action-' + action.module"
            >
              <mat-icon aria-hidden="true">{{ action.icon }}</mat-icon>
              {{ action.label | translate }}
              <span class="twes-soon" data-testid="soon">{{ 'shell.soon' | translate }}</span>
            </button>
          }
        }
        <mat-divider [vertical]="true" class="self-stretch" />
      </div>
    }
  `,
})
export class PlannedActions {
  private readonly session = inject(Session);
  private readonly theme = inject(ThemeFacade);
  private readonly router = inject(Router);
  private readonly windowClass = inject(WINDOW_CLASS);

  readonly actions = input.required<readonly PlannedAction[]>();

  protected readonly compact = computed(() => this.windowClass() === 'compact');
  protected readonly offered = computed(() => {
    if (!this.theme.showComing()) return [];
    const planned = new Set((this.session.me()?.plannedModules ?? []).map((each) => each.key));
    return this.actions().filter((action) => planned.has(action.module));
  });

  protected open(action: PlannedAction): void {
    void this.router.navigateByUrl(`/coming/${action.module}`);
  }
}
