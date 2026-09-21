// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { TranslatePipe } from '@ngx-translate/core';
import { runAction } from '../actions/run-action';
import type { ScreenAction } from '../actions/screen-action';
import { ConfirmDialog } from '../ui/confirm-dialog';

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
  imports: [MatButtonModule, MatIconModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="flex flex-wrap items-center gap-2" data-testid="record-bar">
      @if (changes() > 0) {
        <!-- Announced, because nothing else on the page says a form was left half-filled. -->
        <span
          role="status"
          class="text-sm text-on-surface-variant"
          data-testid="record-changes"
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
          [attr.data-testid]="'record-' + action.id"
        >
          @if (action.icon) {
            <mat-icon aria-hidden="true">{{ action.icon }}</mat-icon>
          }
          {{ action.label | translate: action.labelParams }}
        </button>
      }
      @for (action of primary(); track action.id) {
        <button
          mat-flat-button
          type="button"
          [disabled]="action.disabled === true"
          (click)="run(action)"
          [attr.data-testid]="'record-' + action.id"
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

  /** How many fields hold something other than what was last saved; `dirtyCount` answers it. */
  readonly changes = input.required<number>();
  /** What the page offers, declared once — the same list the keyboard, the palette and the "?" sheet read. */
  readonly actions = input.required<readonly ScreenAction[]>();

  private readonly offered = computed(() =>
    this.actions().filter((action) => action.shown !== false),
  );
  protected readonly plain = computed(() =>
    this.offered().filter((action) => action.primary !== true),
  );
  /** Drawn filled and last: the state's next step, where a person's hand ends up. */
  protected readonly primary = computed(() =>
    this.offered().filter((action) => action.primary === true),
  );

  /**
   * Delegated to the same `runAction` the toolbar, the keyboard and the palette use, so the bar and a keystroke
   * cannot come to different answers about one action. This knows only how to ASK.
   */
  protected run(action: ScreenAction): void {
    runAction(action, (confirm) =>
      this.dialog.open(ConfirmDialog, { data: confirm, autoFocus: 'dialog' }).afterClosed(),
    );
  }
}
