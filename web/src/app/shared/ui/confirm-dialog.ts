// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { TranslatePipe } from '@ngx-translate/core';
import { ACTION_KIND_ICONS, type ActionConfirm } from '../actions/screen-action';

/**
 * The question asked before something destructive runs. A dialog rather than a second button appearing in place of
 * the first: the action now lives in a menu, and a menu entry that turns into two entries is a place to misclick.
 * Below the question it says whether what follows can be taken back (docs/SPEC.md § 7, 2026-09-25 22:17), apart
 * from the message, so the same three words read the same on every confirmation.
 */
@Component({
  selector: 'app-confirm-dialog',
  imports: [MatButtonModule, MatDialogModule, MatIconModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title data-testid="confirm-title">{{ data.title | translate }}</h2>
    <mat-dialog-content>
      <p data-testid="confirm-message">{{ data.message | translate: data.messageParams }}</p>
      <p
        class="mt-3 flex items-start gap-2 text-sm"
        data-testid="confirm-kind"
        data-tour="action-kind"
        [attr.data-kind]="data.kind"
      >
        <mat-icon aria-hidden="true" class="shrink-0">{{ icons[data.kind] }}</mat-icon>
        <span
          ><strong>{{ 'actions.kind.' + data.kind | translate }}</strong> ·
          {{ 'actions.kind_help.' + data.kind | translate }}</span
        >
      </p>
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
  protected readonly icons = ACTION_KIND_ICONS;
}
