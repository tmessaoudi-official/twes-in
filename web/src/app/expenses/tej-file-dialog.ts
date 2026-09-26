// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatSelectModule } from '@angular/material/select';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { FileSaver } from '../shared/files/save-file';
import type { FieldOption } from '../shared/form/form-types';
import { DayPipe } from '../shared/i18n/format-pipes';
import { ExpensesFacade } from './expenses-facade';
import type { TejFileAnswer } from './expenses-types';

export interface TejFileDialogData {
  companyId: string;
  /** The months that are over, the latest first, each named for the reader. */
  months: FieldOption[];
}

/**
 * A month's withholdings as the TEJ platform's file (docs/SPEC.md § 7, 2026-09-26 00:22): downloaded for the person
 * to file on the platform, or, when a payment holds the month back, each such payment named with what it lacks.
 * The screen says what the file is, and nothing about whether the platform will accept it.
 */
@Component({
  selector: 'app-tej-file-dialog',
  imports: [
    MatButtonModule,
    MatDialogModule,
    MatFormFieldModule,
    MatSelectModule,
    RouterLink,
    TranslatePipe,
    DayPipe,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title data-testid="tej-file-title">
      {{ 'expenses.tej_file.title' | translate }}
    </h2>
    <mat-dialog-content class="flex flex-col gap-4">
      <p class="text-sm text-on-surface-variant">{{ 'expenses.tej_file.intro' | translate }}</p>
      <!-- One question, so one field: the month, the last one over first. -->
      <mat-form-field appearance="outline" class="mt-2 w-full">
        <mat-label>{{ 'expenses.tej_file.month' | translate }}</mat-label>
        <mat-select [value]="month()" (valueChange)="month.set($event)" data-testid="tej-month">
          @for (option of months; track option.value) {
            <mat-option [value]="option.value">{{ option.label }}</mat-option>
          }
        </mat-select>
      </mat-form-field>
      @if (refusal(); as refused) {
        <div role="alert" class="flex flex-col gap-2" data-testid="tej-refusal">
          <p class="font-semibold text-error">
            {{ 'expenses.tej_file.refused.' + refused.code | translate: refused.params }}
          </p>
          @if (refused.expenses.length > 0) {
            <ul class="flex flex-col gap-2">
              @for (expense of refused.expenses; track expense.id) {
                <li class="text-sm" [attr.data-testid]="'tej-refused-' + expense.id">
                  <a [routerLink]="['/expenses', expense.id]" (click)="ref.close(false)">
                    {{ expense.description }}
                  </a>
                  · {{ expense.paidOn | day }}
                  @if (expense.vendorName) {
                    · {{ expense.vendorName }}
                  }
                  <span class="block text-on-surface-variant">
                    @for (problem of expense.problems; track problem; let last = $last) {
                      {{ 'expenses.tej_file.problems.' + problem | translate
                      }}{{ last ? '' : ' · ' }}
                    }
                  </span>
                </li>
              }
            </ul>
          }
        </div>
      }
      @if (failed()) {
        <p role="alert" class="text-error" data-testid="tej-failed">
          {{ 'expenses.tej_file.failed' | translate }}
        </p>
      }
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button type="button" (click)="ref.close(false)" data-testid="tej-close">
        {{ 'expenses.tej_file.close' | translate }}
      </button>
      <button
        mat-flat-button
        type="button"
        (click)="download()"
        [disabled]="facade.busy()"
        data-testid="tej-download"
      >
        {{ 'expenses.tej_file.download' | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class TejFileDialog {
  protected readonly ref = inject<MatDialogRef<TejFileDialog, boolean>>(MatDialogRef);
  protected readonly facade = inject(ExpensesFacade);
  private readonly data = inject<TejFileDialogData>(MAT_DIALOG_DATA);
  private readonly saver = inject(FileSaver);

  protected readonly months = this.data.months;
  protected readonly month = signal(this.data.months[0]?.value ?? '');
  protected readonly refusal = signal<Extract<TejFileAnswer, { kind: 'refused' }> | null>(null);
  protected readonly failed = signal(false);

  protected async download(): Promise<void> {
    const month = this.month();
    if (month === '' || this.facade.busy()) return;
    this.refusal.set(null);
    const answer = await this.facade.tejFile(this.data.companyId, month);
    this.failed.set(answer === null);
    if (answer === null) return;
    if (answer.kind === 'refused') {
      this.refusal.set(answer);
      return;
    }
    this.saver.save(answer.file, answer.filename);
    this.ref.close(true);
  }
}
