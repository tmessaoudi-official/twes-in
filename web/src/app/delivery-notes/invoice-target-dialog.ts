// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { TranslatePipe } from '@ngx-translate/core';
import { AmountPipe } from '../shared/i18n/format-pipes';
import type { InvoiceDraftOption } from './delivery-notes-types';

export interface InvoiceTargetData {
  drafts: readonly InvoiceDraftOption[];
  /** The decimals of the company's currency. */
  scale: number;
}

/** Where the note goes: a new invoice, or one of the drafts it could be added to. */
export interface InvoiceTarget {
  readonly draftId: string | null;
}

/**
 * Asks where the delivery note is invoiced (docs/SPEC.md § 7, 2026-10-04): on a new invoice, or added to a draft of the
 * same customer and establishment. An issued invoice is never offered, since a credit note corrects it. It closes on the
 * target, or on `null` when nothing was chosen.
 */
@Component({
  selector: 'app-invoice-target-dialog',
  imports: [MatButtonModule, MatDialogModule, TranslatePipe, AmountPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title data-testid="invoice-target-title">
      {{ 'delivery_notes.invoice_target.title' | translate }}
    </h2>
    <mat-dialog-content>
      <p class="text-sm text-on-surface-variant">
        {{ 'delivery_notes.invoice_target.help' | translate }}
      </p>
      <div class="flex flex-col gap-2">
        <button
          mat-flat-button
          type="button"
          (click)="ref.close({ draftId: null })"
          data-testid="invoice-target-new"
        >
          {{ 'delivery_notes.invoice_target.new' | translate }}
        </button>
        @for (draft of data.drafts; track draft.id) {
          <button
            mat-stroked-button
            type="button"
            (click)="ref.close({ draftId: draft.id })"
            [attr.data-testid]="'invoice-target-' + draft.id"
          >
            {{
              'delivery_notes.invoice_target.draft'
                | translate
                  : {
                      total: (draft.total | amount: data.scale),
                      lines: draft.lineCount,
                      reference: draft.customerReference ?? '',
                    }
            }}
          </button>
        }
      </div>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button
        mat-button
        type="button"
        (click)="ref.close(null)"
        data-testid="invoice-target-cancel"
      >
        {{ 'delivery_notes.invoice_target.cancel' | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class InvoiceTargetDialog {
  protected readonly ref =
    inject<MatDialogRef<InvoiceTargetDialog, InvoiceTarget | null>>(MatDialogRef);
  protected readonly data = inject<InvoiceTargetData>(MAT_DIALOG_DATA);
}
