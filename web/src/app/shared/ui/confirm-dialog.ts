// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { TranslatePipe } from '@ngx-translate/core';
import type { ActionConfirm } from '../actions/screen-action';

/**
 * The question asked before something destructive runs. A dialog rather than a second button appearing in place of
 * the first: the action now lives in a menu, and a menu entry that turns into two entries is a place to misclick.
 */
@Component({
  selector: 'app-confirm-dialog',
  imports: [MatButtonModule, MatDialogModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title data-testid="confirm-title">{{ data.title | translate }}</h2>
    <mat-dialog-content>
      <p data-testid="confirm-message">{{ data.message | translate: data.messageParams }}</p>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button type="button" (click)="ref.close(false)" data-testid="confirm-keep">
        {{ data.keepLabel | translate }}
      </button>
      <button mat-flat-button type="button" (click)="ref.close(true)" data-testid="confirm-run">
        {{ data.confirmLabel | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class ConfirmDialog {
  protected readonly ref = inject<MatDialogRef<ConfirmDialog, boolean>>(MatDialogRef);
  protected readonly data = inject<ActionConfirm>(MAT_DIALOG_DATA);
}
