// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule } from '@angular/material/dialog';
import { TranslatePipe } from '@ngx-translate/core';
import type { BulkCheck } from './invoices-types';

/** What the dialog is told: the check the API made, and the words of the action that waits on it. */
export interface BulkSkippedData {
  check: BulkCheck;
  /** The action's own word on its button, taking `count`: « Exporter les 4 autres ». */
  goKey: string;
}

/**
 * The selected invoices a bulk action cannot take, each named with why, before it acts on the others: nothing is left
 * out without being said. It closes with true when the person goes on with the rest.
 */
@Component({
  selector: 'app-invoice-bulk-skipped-dialog',
  imports: [MatButtonModule, MatDialogModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title>
      {{ 'invoices.bulk.skipped_title' | translate: { count: data.check.skipped } }}
    </h2>
    <mat-dialog-content>
      <ul class="flex flex-col gap-2" data-testid="invoice-bulk-skipped">
        @for (row of skipped; track row.id) {
          <li class="flex flex-col" [attr.data-testid]="'invoice-bulk-skipped-' + row.id">
            <span class="font-medium">{{
              row.number ?? row.customerName ?? ('invoices.bulk.unknown' | translate)
            }}</span>
            <span class="text-sm text-on-surface-variant">{{
              'invoices.bulk.reasons.' + row.reason | translate
            }}</span>
          </li>
        }
      </ul>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button type="button" [mat-dialog-close]="false" data-testid="invoice-bulk-cancel">
        {{
          (data.check.included === 0 ? 'invoices.bulk.close' : 'invoices.bulk.cancel') | translate
        }}
      </button>
      @if (data.check.included > 0) {
        <button
          mat-flat-button
          type="button"
          [mat-dialog-close]="true"
          data-testid="invoice-bulk-go"
        >
          {{ data.goKey | translate: { count: data.check.included } }}
        </button>
      }
    </mat-dialog-actions>
  `,
})
export class InvoiceBulkSkippedDialog {
  protected readonly data = inject<BulkSkippedData>(MAT_DIALOG_DATA);
  protected readonly skipped = this.data.check.rows.filter((row) => !row.included);
}
