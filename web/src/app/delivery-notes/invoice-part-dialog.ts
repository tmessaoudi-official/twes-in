// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { TranslatePipe } from '@ngx-translate/core';
import { DecimalInput } from '../shared/form/decimal-input';
import { AmountPipe } from '../shared/i18n/format-pipes';

/** One line of the note as the dialog offers it: what it says, and what is still to invoice of it. */
export interface InvoicePartLine {
  lineId: string;
  description: string;
  quantity: string;
  left: string;
}

/**
 * Asks how much of each line an invoice takes (docs/SPEC.md § 7): every line starts at what is left of it, a line
 * with nothing left is not offered, and a quantity of zero leaves the line out. It closes on the quantities by line
 * id, or on `null` when nothing was asked of it. What is more than is left is the API's to refuse, in exact decimals.
 */
@Component({
  selector: 'app-invoice-part-dialog',
  imports: [
    AmountPipe,
    DecimalInput,
    FormsModule,
    MatButtonModule,
    MatDialogModule,
    MatFormFieldModule,
    MatInputModule,
    TranslatePipe,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title data-testid="invoice-part-title">
      {{ 'delivery_notes.invoice_part.title' | translate }}
    </h2>
    <mat-dialog-content>
      <p class="text-sm text-on-surface-variant">
        {{ 'delivery_notes.invoice_part.help' | translate }}
      </p>
      <div class="flex flex-col gap-3">
        @for (line of lines; track line.lineId; let index = $index) {
          <mat-form-field subscriptSizing="dynamic" class="w-full">
            <mat-label>{{ line.description }}</mat-label>
            <input
              matInput
              appDecimal
              autocomplete="off"
              [ngModel]="quantities()[line.lineId]"
              (ngModelChange)="onQuantity(line.lineId, $event)"
              [attr.data-testid]="'invoice-part-quantity-' + index"
            />
            <mat-hint [attr.data-testid]="'invoice-part-left-' + index">
              {{
                'delivery_notes.invoice_part.left' | translate: { left: line.left | amount: null }
              }}
            </mat-hint>
          </mat-form-field>
        }
      </div>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button type="button" (click)="ref.close(null)" data-testid="invoice-part-cancel">
        {{ 'delivery_notes.invoice_part.cancel' | translate }}
      </button>
      <button
        mat-flat-button
        type="button"
        [disabled]="!any()"
        (click)="ref.close(chosen())"
        data-testid="invoice-part-confirm"
      >
        {{ 'delivery_notes.invoice_part.confirm' | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class InvoicePartDialog {
  protected readonly ref =
    inject<MatDialogRef<InvoicePartDialog, Record<string, string> | null>>(MatDialogRef);
  /** Only the lines with something left. */
  protected readonly lines = inject<InvoicePartLine[]>(MAT_DIALOG_DATA)
    .filter((line) => !/^-?0*(\.0*)?$/.test(line.left))
    .map((line) => ({ ...line, left: plainQuantity(line.left) }));
  protected readonly quantities = signal<Record<string, string>>(
    Object.fromEntries(this.lines.map((line) => [line.lineId, line.left])),
  );

  /** The lines given a quantity above zero: a blank or a zero leaves the line out. */
  protected chosen(): Record<string, string> {
    const kept: Record<string, string> = {};
    for (const [lineId, quantity] of Object.entries(this.quantities())) {
      const text = quantity.trim().replace(',', '.');
      if (!/^-?0*(\.0*)?$/.test(text)) kept[lineId] = text;
    }
    return kept;
  }

  protected any(): boolean {
    return Object.keys(this.chosen()).length > 0;
  }

  /** What the field holds: the API's point, whatever separator was typed (`appDecimal`). */
  protected onQuantity(lineId: string, value: unknown): void {
    this.quantities.update((all) => ({ ...all, [lineId]: typeof value === 'string' ? value : '' }));
  }
}

/** "4.500" as "4.5": the zeros the API pads a quantity with say nothing to the person reading it. */
function plainQuantity(value: string): string {
  return value.includes('.') ? value.replace(/0+$/, '').replace(/\.$/, '') : value;
}
