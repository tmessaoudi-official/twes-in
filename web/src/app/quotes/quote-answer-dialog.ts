// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { TranslatePipe } from '@ngx-translate/core';
import { DayCalendarButton } from '../shared/form/day-calendar-button';
import { DayInput } from '../shared/form/day-input';
import { FileDrop } from '../shared/form/file-drop';
import { ATTACHMENT_MAX_BYTES } from '../shared/form/file-limits';
import { REFUSAL_REASON_MAX } from './quote-forms';

export interface QuoteAnswerDialogData {
  kind: 'accept' | 'refuse';
}

/** What the customer answered: the day (empty: the company's today), why they declined, and the signed copy. */
export interface QuoteAnswered {
  answeredOn: string;
  refusalReason: string;
  signed: File | null;
}

/**
 * The customer's answer to a sent quote, asked when it is recorded: the day they gave it, and either the copy they
 * signed (« Bon pour accord »), which is attached to the quote, or why they declined. Both are optional; the day,
 * left empty, is the company's today.
 */
@Component({
  selector: 'app-quote-answer-dialog',
  imports: [
    FormsModule,
    DayInput,
    DayCalendarButton,
    FileDrop,
    MatButtonModule,
    MatDialogModule,
    MatFormFieldModule,
    MatInputModule,
    TranslatePipe,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title data-testid="quote-answer-title">
      {{ 'quotes.answer.' + data.kind + '_title' | translate }}
    </h2>
    <mat-dialog-content>
      <!-- Material's own display beats a layout class on mat-dialog-content: the layout goes on a div inside it. -->
      <div class="flex flex-col gap-4">
        <mat-form-field subscriptSizing="dynamic" class="w-full">
          <mat-label>{{ 'quotes.answer.answered_on' | translate }}</mat-label>
          <input
            matInput
            appDay
            #dayField="appDay"
            type="text"
            inputmode="numeric"
            autocomplete="off"
            [ngModel]="day()"
            (ngModelChange)="day.set($event)"
            data-testid="quote-answered-on"
          />
          <app-day-calendar-button matSuffix [field]="dayField" />
          <mat-hint>{{ 'quotes.answer.answered_on_hint' | translate }}</mat-hint>
        </mat-form-field>
        @if (data.kind === 'accept') {
          <app-file-drop
            [label]="'quotes.answer.signed' | translate"
            accept="application/pdf,image/png,image/jpeg,image/webp"
            [maxBytes]="maxBytes"
            [chosenName]="signed()?.name ?? null"
            testId="quote-signed"
            (picked)="signed.set($event)"
          />
        } @else {
          <mat-form-field subscriptSizing="dynamic" class="w-full">
            <mat-label>{{ 'quotes.answer.reason' | translate }}</mat-label>
            <textarea
              matInput
              rows="3"
              [maxlength]="reasonMax"
              [ngModel]="reason()"
              (ngModelChange)="reason.set($event)"
              data-testid="quote-refusal-reason"
            ></textarea>
            <mat-hint>{{ 'quotes.answer.reason_hint' | translate }}</mat-hint>
          </mat-form-field>
        }
      </div>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button type="button" (click)="ref.close(null)" data-testid="quote-answer-keep">
        {{ 'quotes.actions.keep' | translate }}
      </button>
      <button
        mat-flat-button
        type="button"
        [disabled]="!valid()"
        (click)="answer()"
        data-testid="quote-answer-confirm"
      >
        {{ 'quotes.answer.' + data.kind + '_confirm' | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class QuoteAnswerDialog {
  protected readonly ref =
    inject<MatDialogRef<QuoteAnswerDialog, QuoteAnswered | null>>(MatDialogRef);
  protected readonly data = inject<QuoteAnswerDialogData>(MAT_DIALOG_DATA);
  protected readonly maxBytes = ATTACHMENT_MAX_BYTES;
  protected readonly reasonMax = REFUSAL_REASON_MAX;
  protected readonly day = signal('');
  protected readonly reason = signal('');
  protected readonly signed = signal<File | null>(null);

  protected readonly valid = computed(
    () => this.day() === '' || /^\d{4}-\d{2}-\d{2}$/.test(this.day()),
  );

  protected answer(): void {
    if (!this.valid()) return;
    this.ref.close({
      answeredOn: this.day(),
      refusalReason: this.data.kind === 'refuse' ? this.reason().trim() : '',
      signed: this.data.kind === 'accept' ? this.signed() : null,
    });
  }
}
