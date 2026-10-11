// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MAT_DIALOG_DATA, MatDialogModule } from '@angular/material/dialog';
import { TranslatePipe } from '@ngx-translate/core';
import type { BulkCheck } from './invoices-types';

/** What the dialog is told: the check the API made, and the words of the action that waits on it. */
export interface BulkSkippedData {
  check: BulkCheck;
  /** The action's own word on its button, taking `count`: « Exporter les 4 autres ». */
  goKey: string;
  /** Whether the drafts and cancelled ones selected may be taken after all, each kind by its own box. */
  offersBoxes?: boolean;
}

/** What the person went on with: which kinds left out by default they asked for too. */
export interface BulkSkippedChoice {
  drafts: boolean;
  cancelled: boolean;
}

/**
 * The selected invoices a bulk action cannot take, each named with why, before it acts on the others: nothing is left
 * out without being said. A PDF may take the drafts and the cancelled ones after all, each kind by its own box, shown
 * only when the selection holds one and unticked at every opening, so a watermarked page is never sent by a choice made
 * before. It closes with the choice when the person goes on, and with nothing otherwise.
 */
@Component({
  selector: 'app-invoice-bulk-skipped-dialog',
  imports: [MatButtonModule, MatCheckboxModule, MatDialogModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title>{{ 'invoices.bulk.skipped_title' | translate: { count: left() } }}</h2>
    <mat-dialog-content>
      <div class="flex flex-col gap-2">
        @if (has('draft')) {
          <mat-checkbox
            [checked]="drafts()"
            (change)="drafts.set($event.checked)"
            data-testid="invoice-bulk-include-drafts"
          >
            {{ 'invoices.bulk.include_drafts' | translate }}
          </mat-checkbox>
        }
        @if (has('cancelled')) {
          <mat-checkbox
            [checked]="cancelled()"
            (change)="cancelled.set($event.checked)"
            data-testid="invoice-bulk-include-cancelled"
          >
            {{ 'invoices.bulk.include_cancelled' | translate }}
          </mat-checkbox>
        }
        <ul class="flex flex-col gap-2" data-testid="invoice-bulk-skipped">
          @for (row of skipped(); track row.id) {
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
      </div>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button type="button" [mat-dialog-close]="null" data-testid="invoice-bulk-cancel">
        {{ (included() === 0 ? 'invoices.bulk.close' : 'invoices.bulk.cancel') | translate }}
      </button>
      @if (included() > 0) {
        <button
          mat-flat-button
          type="button"
          [mat-dialog-close]="choice()"
          data-testid="invoice-bulk-go"
        >
          {{ data.goKey | translate: { count: included() } }}
        </button>
      }
    </mat-dialog-actions>
  `,
})
export class InvoiceBulkSkippedDialog {
  protected readonly data = inject<BulkSkippedData>(MAT_DIALOG_DATA);
  protected readonly drafts = signal(false);
  protected readonly cancelled = signal(false);

  protected readonly choice = computed<BulkSkippedChoice>(() => ({
    drafts: this.drafts(),
    cancelled: this.cancelled(),
  }));

  /** What stays out once the ticked boxes have taken their kind back. */
  protected readonly skipped = computed(() =>
    this.data.check.rows.filter(
      (row) =>
        !row.included &&
        !(row.reason === 'draft' && this.drafts()) &&
        !(row.reason === 'cancelled' && this.cancelled()),
    ),
  );

  /**
   * How many stay out. The title is written on one line: Material's title draws an inline mark before its text for its
   * baseline, and the space a line break leaves would show after it.
   */
  protected readonly left = computed(() => this.skipped().length);

  protected readonly included = computed(() => this.data.check.rows.length - this.skipped().length);

  protected has(reason: 'draft' | 'cancelled'): boolean {
    return (
      this.data.offersBoxes === true && this.data.check.rows.some((row) => row.reason === reason)
    );
  }
}
