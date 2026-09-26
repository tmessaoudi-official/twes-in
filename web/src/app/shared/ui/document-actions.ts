// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDividerModule } from '@angular/material/divider';
import { MatDialog } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { Label } from '../a11y/label';
import { runAction } from '../actions/run-action';
import { type PlannedAction, PlannedActions } from '../actions/planned-actions';
import { kindOf, type ScreenAction } from '../actions/screen-action';
import { ConfirmDialog } from './confirm-dialog';

/**
 * The bar beside a document's title (design review finding 3). It stays in view while the page scrolls, because the
 * measured defect was the next step sitting below 2000 px of form. The screen declares what it offers; this decides
 * only where each one is drawn.
 */
@Component({
  selector: 'app-document-actions',
  imports: [
    MatButtonModule,
    MatDividerModule,
    MatIconModule,
    MatMenuModule,
    RouterLink,
    TranslatePipe,
    Label,
    PlannedActions,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './document-actions.html',
})
export class DocumentActions {
  private readonly dialog = inject(MatDialog);

  readonly actions = input.required<readonly ScreenAction[]>();
  /** What the screen will offer once a planned module ships, drawn first and marked « Bientôt » (row 150). */
  readonly planned = input<readonly PlannedAction[]>([]);
  /** Named for a screen reader, since several bars can exist on one page in principle. */
  readonly label = input('document.actions');

  private readonly offered = computed(() =>
    this.actions().filter((action) => action.shown !== false),
  );
  /** Drawn in the bar: the frequent ones, never anything destructive. */
  protected readonly visible = computed(() =>
    this.offered().filter((action) => action.rare !== true && action.destructive !== true),
  );
  /** Folded into "⋮": the rare and everything destructive, whatever its frequency. */
  protected readonly rare = computed(() =>
    this.offered().filter((action) => action.rare === true || action.destructive === true),
  );
  /**
   * The folded ones that can be taken back or corrected, first; what is final last, under its own heading, so it is
   * never the entry beside the pointer (docs/SPEC.md § 7, 2026-09-25 22:17 and NAV-38).
   */
  protected readonly menu = computed(() => [
    ...this.rare().filter((action) => kindOf(action) !== 'definitif'),
    ...this.rare().filter((action) => kindOf(action) === 'definitif'),
  ]);
  /** The entry the « Définitif » heading is drawn above; one loop, so the menu keeps its keyboard order. */
  protected readonly firstFinal = computed(() =>
    this.menu().find((action) => kindOf(action) === 'definitif'),
  );

  protected kind(action: ScreenAction): string | null {
    return kindOf(action) ?? null;
  }

  /**
   * Delegated rather than decided here, so the bar, the keyboard shortcut and the palette cannot come to different
   * answers about the same action: this component only knows how to ASK — the rest is `runAction`.
   */
  protected run(action: ScreenAction): void {
    runAction(action, (confirm) =>
      this.dialog.open(ConfirmDialog, { data: confirm, autoFocus: 'dialog' }).afterClosed(),
    );
  }
}
