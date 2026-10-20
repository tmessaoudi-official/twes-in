// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog } from '@angular/material/dialog';
import { MatDividerModule } from '@angular/material/divider';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { Label } from '../a11y/label';
import type { PlannedAction } from '../actions/planned-actions';
import { plannedRoom } from '../actions/planned-room';
import { runAction } from '../actions/run-action';
import { kindOf, type ActionConfirm, type ScreenAction } from '../actions/screen-action';
import { Session } from '../session/session';
import { ThemeFacade } from '../theme/theme-facade';
import { ConfirmDialog } from '../ui/confirm-dialog';
import { WINDOW_CLASS } from '../ui/window-class';

/**
 * Saving a record from the bar beside its title (docs/SPEC.md § 7, 2026-09-19 23:18, design review finding 4,
 * measured: every long form's save sat below the first screen, the customer page at 3165 px with two of them).
 *
 * It draws what the PAGE declared (row 45, row 106) rather than a fixed save-and-revert pair: the same list reaches
 * the keyboard, the Ctrl K palette and the "?" sheet, so a record page cannot offer something in one of them and
 * lack it in another — which is exactly what these pages did while the bar carried its own outputs.
 *
 * Beside them it shows the count of what is unsaved, announced, because nothing else on the page says a form was
 * left half-filled. The next step is drawn last, where a person's hand ends up.
 */
@Component({
  selector: 'app-record-bar',
  imports: [
    MatButtonModule,
    MatDividerModule,
    MatIconModule,
    MatMenuModule,
    RouterLink,
    TranslatePipe,
    Label,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div
      class="flex flex-wrap items-center gap-2"
      [attr.data-testid]="testId() + '-bar'"
      data-tour="record-bar"
    >
      @if (changes() > 0) {
        <!-- Announced, because nothing else on the page says a form was left half-filled. -->
        <span
          role="status"
          class="text-sm text-on-surface-variant"
          [attr.data-testid]="testId() + '-changes'"
          [attr.data-count]="changes()"
        >
          {{
            (changes() === 1 ? 'form.one_unsaved' : 'form.unsaved')
              | translate: { count: changes() }
          }}
        </span>
      }
      @for (action of plain(); track action.id) {
        <button
          mat-button
          type="button"
          [disabled]="action.disabled === true"
          (click)="run(action)"
          [attr.data-testid]="testId() + '-' + action.id"
        >
          @if (action.icon) {
            <mat-icon aria-hidden="true">{{ action.icon }}</mat-icon>
          }
          {{ action.label | translate: action.labelParams }}
        </button>
      }
      @for (action of barComing(); track action.module) {
        <!-- Kept out of the flow until the header has room for it, so its width is known before it is shown. -->
        <a
          mat-stroked-button
          class="twes-planned-action"
          [class.twes-planned-measure]="!plannedFit()"
          [attr.aria-hidden]="plannedFit() ? null : 'true'"
          [tabIndex]="plannedFit() ? 0 : -1"
          [routerLink]="['/coming', action.module]"
          data-planned
          [attr.data-testid]="'planned-action-' + action.module"
        >
          <mat-icon aria-hidden="true">{{ action.icon }}</mat-icon>
          {{ action.label | translate }}
          <span class="twes-soon" data-testid="soon">{{ 'shell.soon' | translate }}</span>
        </a>
      }
      @if (folded().length > 0 || menuComing().length > 0) {
        <button
          mat-icon-button
          type="button"
          [matMenuTriggerFor]="moreMenu"
          [appLabel]="'document.more_actions' | translate"
          [attr.data-testid]="testId() + '-more'"
          data-bar-more
        >
          <mat-icon aria-hidden="true">more_vert</mat-icon>
        </button>
        <mat-menu #moreMenu="matMenu" xPosition="before">
          @for (action of folded(); track action.id) {
            <button
              mat-menu-item
              type="button"
              [disabled]="action.disabled === true"
              [class.text-error]="action.destructive"
              (click)="run(action)"
              [attr.data-testid]="testId() + '-menu-' + action.id"
              [attr.data-kind]="kind(action)"
            >
              @if (action.icon) {
                <mat-icon aria-hidden="true" [class.text-error]="action.destructive">{{
                  action.icon
                }}</mat-icon>
              }
              <span>{{ action.label | translate: action.labelParams }}</span>
            </button>
          }
          @if (menuComing().length > 0) {
            @if (folded().length > 0) {
              <mat-divider />
            }
            <div
              class="px-4 pt-2 pb-1 text-xs font-semibold uppercase tracking-wide text-on-surface-variant"
              [attr.data-testid]="testId() + '-planned-heading'"
            >
              {{ 'actions.coming' | translate }}
            </div>
            @for (action of menuComing(); track action.module) {
              <button
                mat-menu-item
                type="button"
                (click)="openComing(action)"
                [attr.data-testid]="testId() + '-planned-' + action.module"
              >
                <mat-icon aria-hidden="true">{{ action.icon }}</mat-icon>
                <span class="twes-rail-title">
                  <span>{{ action.label | translate }}</span>
                  <span class="twes-soon" data-testid="soon">{{ 'shell.soon' | translate }}</span>
                </span>
              </button>
            }
          }
        </mat-menu>
      }
      @for (action of primary(); track action.id) {
        <button
          mat-flat-button
          type="button"
          [disabled]="action.disabled === true"
          (click)="run(action)"
          [attr.data-testid]="testId() + '-' + action.id"
        >
          @if (action.icon) {
            <mat-icon aria-hidden="true">{{ action.icon }}</mat-icon>
          }
          {{ action.label | translate: action.labelParams }}
        </button>
      }
    </div>
  `,
})
export class RecordBar {
  private readonly dialog = inject(MatDialog);
  private readonly router = inject(Router);
  private readonly session = inject(Session);
  private readonly theme = inject(ThemeFacade);
  private readonly windowClass = inject(WINDOW_CLASS);

  /** How many fields hold something other than what was last saved; `dirtyCount` answers it. */
  readonly changes = input.required<number>();
  /** What the page offers, declared once — the same list the keyboard, the palette and the "?" sheet read. */
  readonly actions = input.required<readonly ScreenAction[]>();
  /**
   * What the screen will offer once a planned module ships, as on a document's bar: dashed in the bar where the header
   * has room for them, else last in the « ⋮ » under their own heading.
   */
  readonly planned = input<readonly PlannedAction[]>([]);
  /**
   * What the bar's test ids start with. A tab that saves its own form beside the record's draws a second bar on the
   * same page, which needs ids of its own.
   */
  readonly testId = input('record');

  private readonly offered = computed(() =>
    this.actions().filter((action) => action.shown !== false),
  );
  private readonly compact = computed(() => this.windowClass() === 'compact');
  private readonly secondary = computed(() =>
    this.offered().filter((action) => action.primary !== true),
  );
  /**
   * Drawn beside the next step; on a phone folded into the « ⋮ », where they stacked one per row (audit V-12). A delete is
   * folded on every window and listed last, never a button a hand reaches for on its way to Enregistrer.
   */
  protected readonly plain = computed(() =>
    this.compact() ? [] : this.secondary().filter((action) => action.destructive !== true),
  );
  protected readonly folded = computed(() => [
    ...(this.compact() ? this.secondary().filter((action) => action.destructive !== true) : []),
    ...this.secondary().filter((action) => action.destructive === true),
  ]);
  /** The planned actions shown: those the catalogue still plans, and none once « Montrer ce qui arrive » is off. */
  protected readonly coming = computed(() => {
    if (!this.theme.showComing()) return [];
    const planned = new Set((this.session.me()?.plannedModules ?? []).map((each) => each.key));
    return this.planned().filter((action) => planned.has(action.module));
  });
  /** Drawn in the bar on a wide window: shown where they fit, else kept out of the flow and measured. */
  protected readonly barComing = computed(() => (this.compact() ? [] : this.coming()));
  protected readonly plannedFit = plannedRoom(
    () => this.barComing(),
    () => this.folded().length > 0,
  );
  /** Listed in « ⋮ » where the bar does not show them. */
  protected readonly menuComing = computed(() =>
    this.barComing().length > 0 && this.plannedFit() ? [] : this.coming(),
  );
  /** Drawn filled and last, after « ⋮ »: the state's next step, where a person's hand ends up. */
  protected readonly primary = computed(() =>
    this.offered().filter((action) => action.primary === true),
  );

  /**
   * Delegated to the same `runAction` the toolbar, the keyboard and the palette use, so the bar and a keystroke
   * cannot come to different answers about one action. This knows only how to ASK.
   */
  protected openComing(action: PlannedAction): void {
    void this.router.navigateByUrl(`/coming/${action.module}`);
  }

  protected kind(action: ScreenAction): string | null {
    return kindOf(action) ?? null;
  }

  protected run(action: ScreenAction): void {
    runAction(action, (confirm) =>
      this.dialog
        .open<ConfirmDialog, ActionConfirm, boolean>(ConfirmDialog, {
          data: confirm,
          autoFocus: 'dialog',
        })
        .afterClosed(),
    );
  }
}
