// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDividerModule } from '@angular/material/divider';
import { MatDialog } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { Label } from '../a11y/label';
import { runAction } from '../actions/run-action';
import type { PlannedAction } from '../actions/planned-actions';
import { kindOf, type ScreenAction } from '../actions/screen-action';
import { Session } from '../session/session';
import { ThemeFacade } from '../theme/theme-facade';
import { ConfirmDialog } from './confirm-dialog';
import { WINDOW_CLASS } from './window-class';

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
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './document-actions.html',
})
export class DocumentActions {
  private readonly dialog = inject(MatDialog);
  private readonly router = inject(Router);
  private readonly session = inject(Session);
  private readonly theme = inject(ThemeFacade);
  private readonly windowClass = inject(WINDOW_CLASS);

  readonly actions = input.required<readonly ScreenAction[]>();
  /**
   * What the screen will offer once a planned module ships (row 150): listed last in the « ⋮ » menu under its own
   * heading, each marked « Bientôt ». In the bar, first, they pushed the working actions onto a second row on a
   * desktop and drew a second « ⋯ » on a phone (audit V-3, V-5, V-29).
   */
  readonly planned = input<readonly PlannedAction[]>([]);
  /** Named for a screen reader, since several bars can exist on one page in principle. */
  readonly label = input('document.actions');

  private readonly offered = computed(() =>
    this.actions().filter((action) => action.shown !== false),
  );
  private readonly frequent = computed(() =>
    this.offered().filter((action) => action.rare !== true && action.destructive !== true),
  );
  private readonly compact = computed(() => this.windowClass() === 'compact');
  /**
   * Drawn in the bar: the frequent ones, never anything destructive; on a phone the next step alone, since the bar
   * wrapped into three rows there with its « ⋮ » alone on one (audit V-3).
   */
  protected readonly visible = computed(() =>
    this.compact() ? this.frequent().filter((action) => action.primary === true) : this.frequent(),
  );
  /** The frequent ones a phone has no room for, first in the menu. */
  private readonly folded = computed(() =>
    this.compact() ? this.frequent().filter((action) => action.primary !== true) : [],
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
    ...this.folded(),
    ...this.rare().filter((action) => kindOf(action) !== 'definitif'),
    ...this.rare().filter((action) => kindOf(action) === 'definitif'),
  ]);
  /** The planned actions shown: those the catalogue still plans, and none once « Montrer ce qui arrive » is off. */
  protected readonly coming = computed(() => {
    if (!this.theme.showComing()) return [];
    const planned = new Set((this.session.me()?.plannedModules ?? []).map((each) => each.key));
    return this.planned().filter((action) => planned.has(action.module));
  });

  /** The entry the « Définitif » heading is drawn above; one loop, so the menu keeps its keyboard order. */
  protected readonly firstFinal = computed(() =>
    this.menu().find((action) => kindOf(action) === 'definitif'),
  );

  protected openComing(action: PlannedAction): void {
    void this.router.navigateByUrl(`/coming/${action.module}`);
  }

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
