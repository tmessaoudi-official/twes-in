// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import { quantityRefusals } from '../shared/documents/deliver-part-dialog';
import type { FormAnswer } from '../shared/feedback/request-state';
import { DecimalInput } from '../shared/form/decimal-input';
import type { FieldError } from '../shared/form/form-builder';
import { FormStep } from '../shared/form/form-step';
import { RefusalFigures } from '../shared/form/refusal-figures';
import { AmountPipe } from '../shared/i18n/format-pipes';

/** One line of the note as the dialog offers it: what it says, and what is still to invoice of it. */
export interface InvoicePartLine {
  lineId: string;
  description: string;
  quantity: string;
  left: string;
}

export interface InvoicePartData {
  lines: readonly InvoicePartLine[];
  /**
   * Drafts the invoice of the quantities by line, asking where first, and answers its id; the dialog stays open on a
   * refusal and says it on its line, and as it was when the person leaves that question (`refused: []`).
   */
  invoice: (quantities: Record<string, string>) => Promise<FormAnswer<string>>;
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
            <mat-label
              ><bdi>{{ line.description }}</bdi></mat-label
            >
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
          @if (refused()[line.lineId]; as said) {
            <p
              role="alert"
              class="px-4 text-xs text-error"
              [attr.data-testid]="'invoice-part-error-' + index"
            >
              {{ said.key | translate: said.params }}
            </p>
          }
        }
        @if (step.unplaced(); as said) {
          <p role="alert" class="text-sm text-error" data-testid="invoice-part-refused">
            {{ said.key | translate: said.params }}
          </p>
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
        [disabled]="!any() || step.busy()"
        (click)="invoice()"
        data-testid="invoice-part-confirm"
      >
        {{ 'delivery_notes.invoice_part.confirm' | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class InvoicePartDialog {
  protected readonly ref = inject<MatDialogRef<InvoicePartDialog, string | null>>(MatDialogRef);
  private readonly data = inject<InvoicePartData>(MAT_DIALOG_DATA);
  protected readonly step = new FormStep(inject(TranslateService), inject(RefusalFigures));
  /** What the API refused of each line, by its id, until that line's quantity changes. */
  protected readonly refused = signal<Readonly<Record<string, FieldError>>>({});
  /** Only the lines with something left. */
  protected readonly lines = this.data.lines
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
    if (this.refused()[lineId] !== undefined) {
      this.refused.update((all) => {
        const others = { ...all };
        delete others[lineId];
        return others;
      });
    }
  }

  protected async invoice(): Promise<void> {
    if (!this.any() || this.step.busy()) return;
    this.refused.set({});
    const answer = await this.step.run(
      () => this.data.invoice(this.chosen()),
      (refusals) => {
        const { onLines, rest } = quantityRefusals(refusals, this.lines, this.step);
        this.refused.set(onLines);
        return rest;
      },
    );
    if (answer !== 'refused') this.ref.close(answer?.kept ?? null);
  }
}

/** "4.500" as "4.5": the zeros the API pads a quantity with say nothing to the person reading it. */
function plainQuantity(value: string): string {
  return value.includes('.') ? value.replace(/0+$/, '').replace(/\.$/, '') : value;
}
