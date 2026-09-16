// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MAT_SNACK_BAR_DATA, MatSnackBarRef } from '@angular/material/snack-bar';
import { TranslatePipe } from '@ngx-translate/core';
import { Label } from '../a11y/label';

export interface ToastData {
  readonly kind: 'success' | 'failure';
  readonly key: string;
  readonly params: Record<string, unknown>;
}

/** One outcome, said in a line: its icon, its message and a way to close it. */
@Component({
  selector: 'app-toast',
  imports: [MatButtonModule, MatIconModule, TranslatePipe, Label],
  template: `
    <div class="twes-toast" data-testid="toast" [attr.data-kind]="data.kind">
      <mat-icon aria-hidden="true" class="twes-toast-icon">{{
        data.kind === 'success' ? 'check_circle' : 'error'
      }}</mat-icon>
      <span class="twes-toast-message">{{ data.key | translate: data.params }}</span>
      <button
        mat-icon-button
        type="button"
        [appLabel]="'feedback.close' | translate"
        (click)="ref.dismiss()"
        data-testid="toast-close"
      >
        <mat-icon aria-hidden="true">close</mat-icon>
      </button>
    </div>
  `,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class Toast {
  protected readonly data = inject<ToastData>(MAT_SNACK_BAR_DATA);
  protected readonly ref = inject(MatSnackBarRef);
}
