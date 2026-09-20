// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { Label } from '../a11y/label';
import { ConfirmDialog } from './confirm-dialog';
import type { DocumentAction } from './document-actions-types';

/**
 * The bar beside a document's title (design review finding 3). It stays in view while the page scrolls, because the
 * measured defect was the next step sitting below 2000 px of form. The screen declares what it offers; this decides
 * only where each one is drawn.
 */
@Component({
  selector: 'app-document-actions',
  imports: [MatButtonModule, MatIconModule, MatMenuModule, RouterLink, TranslatePipe, Label],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './document-actions.html',
})
export class DocumentActions {
  private readonly dialog = inject(MatDialog);

  readonly actions = input.required<readonly DocumentAction[]>();
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

  protected run(action: DocumentAction): void {
    if (action.disabled === true || action.run === undefined) return;
    if (action.confirm === undefined) {
      action.run();
      return;
    }
    this.dialog
      .open(ConfirmDialog, { data: action.confirm, autoFocus: 'dialog' })
      .afterClosed()
      .subscribe((confirmed) => {
        if (confirmed === true) action.run?.();
      });
  }
}
