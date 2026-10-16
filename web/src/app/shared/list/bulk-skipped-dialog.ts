// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule } from '@angular/material/dialog';
import { TranslatePipe } from '@ngx-translate/core';

/** One selected record as a bulk check answered it: taken, or left out for a reason the screen translates. */
export interface BulkSkippedRow {
  id: string;
  number: string | null;
  customerName: string | null;
  included: boolean;
  reason: string | null;
}

/** What the dialog is told: the rows checked, where its texts live and the words of the action waiting on it. */
export interface BulkSkippedDialogData {
  rows: readonly BulkSkippedRow[];
  /**
   * The key the feature's texts sit under: `<texts>.skipped_title` and `<texts>.close`, `<texts>.unknown` for a record
   * with neither number nor customer, and `<texts>.reasons.<reason>` for each reason.
   */
  texts: string;
  /** The action's own words on its button, taking `count`: « Facturer les 2 autres ». */
  goKey: string;
  /** What the dialog's parts are found by: `<testId>-skipped`, `-skipped-<id>`, `-go` and `-cancel`. */
  testId: string;
}

/**
 * The selected records a bulk action cannot take, each named with why, before it acts on the others: nothing is left
 * out without being said. It closes on `true` when the person goes on, and on nothing otherwise; with nothing left to
 * take it offers only to close.
 */
@Component({
  selector: 'app-bulk-skipped-dialog',
  imports: [MatButtonModule, MatDialogModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title>{{ titleKey | translate: { count: skipped().length } }}</h2>
    <mat-dialog-content>
      <ul class="flex flex-col gap-2" [attr.data-testid]="data.testId + '-skipped'">
        @for (row of skipped(); track row.id) {
          <li class="flex flex-col" [attr.data-testid]="data.testId + '-skipped-' + row.id">
            <span class="font-medium">{{
              row.number ?? row.customerName ?? (data.texts + '.unknown' | translate)
            }}</span>
            <span class="text-sm text-on-surface-variant">{{
              data.texts + '.reasons.' + row.reason | translate
            }}</span>
          </li>
        }
      </ul>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button
        mat-button
        type="button"
        [mat-dialog-close]="null"
        [attr.data-testid]="data.testId + '-cancel'"
      >
        {{ data.texts + '.close' | translate }}
      </button>
      @if (included() > 0) {
        <button
          mat-flat-button
          type="button"
          [mat-dialog-close]="true"
          [attr.data-testid]="data.testId + '-go'"
        >
          {{ data.goKey | translate: { count: included() } }}
        </button>
      }
    </mat-dialog-actions>
  `,
})
export class BulkSkippedDialog {
  protected readonly data = inject<BulkSkippedDialogData>(MAT_DIALOG_DATA);
  /**
   * The title's key, short enough for the title to stay on one line: Material's title draws an inline mark before its
   * text for its baseline, and the space a line break leaves would show after it.
   */
  protected readonly titleKey = `${this.data.texts}.skipped_title`;
  protected readonly skipped = computed(() => this.data.rows.filter((row) => !row.included));
  protected readonly included = computed(() => this.data.rows.length - this.skipped().length);
}
