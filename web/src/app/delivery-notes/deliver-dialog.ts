// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { TranslatePipe } from '@ngx-translate/core';
import { DayInput } from '../shared/form/day-input';

/**
 * The day a note was delivered, asked when delivering rather than sitting as a field in the action bar. Empty is a
 * real answer — the API then takes today — so the dialog closes on a string, and on `null` only when nothing was
 * asked of it.
 */
@Component({
  selector: 'app-deliver-dialog',
  imports: [
    FormsModule,
    DayInput,
    MatButtonModule,
    MatDialogModule,
    MatFormFieldModule,
    MatInputModule,
    TranslatePipe,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title data-testid="deliver-dialog-title">
      {{ 'delivery_notes.actions.deliver' | translate }}
    </h2>
    <mat-dialog-content>
      <mat-form-field subscriptSizing="dynamic" class="w-full">
        <mat-label>{{ 'delivery_notes.actions.delivered_on' | translate }}</mat-label>
        <input
          matInput
          appDay
          type="text"
          inputmode="numeric"
          autocomplete="off"
          [ngModel]="day()"
          (ngModelChange)="day.set($event)"
          data-testid="delivery-note-delivered-on"
        />
      </mat-form-field>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button type="button" (click)="ref.close(null)" data-testid="deliver-cancel">
        {{ 'delivery_notes.actions.keep' | translate }}
      </button>
      <button
        mat-flat-button
        type="button"
        [disabled]="!valid()"
        (click)="ref.close(day())"
        data-testid="delivery-note-deliver"
      >
        {{ 'delivery_notes.actions.deliver' | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class DeliverDialog {
  protected readonly ref = inject<MatDialogRef<DeliverDialog, string | null>>(MatDialogRef);
  protected readonly day = signal(inject<string>(MAT_DIALOG_DATA));

  protected readonly valid = computed(
    () => this.day() === '' || /^\d{4}-\d{2}-\d{2}$/.test(this.day()),
  );
}
