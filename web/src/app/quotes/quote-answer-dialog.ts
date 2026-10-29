// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  ElementRef,
  inject,
  signal,
} from '@angular/core';
import { FormControl, FormsModule, ReactiveFormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import type { FormAnswer } from '../shared/feedback/request-state';
import { DayCalendarButton } from '../shared/form/day-calendar-button';
import { DayInput } from '../shared/form/day-input';
import { FileDrop } from '../shared/form/file-drop';
import type { FieldError } from '../shared/form/form-builder';
import { FormStep } from '../shared/form/form-step';
import { RefusalFigures } from '../shared/form/refusal-figures';
import { revealInvalid } from '../shared/form/reveal-invalid';
import { lengthSaid } from '../shared/form/length-said';
import { RefusedField } from '../shared/form/refused-field';
import { ATTACHMENT_MAX_BYTES } from '../shared/form/file-limits';
import {
  type ReceiverChoice,
  type ReceiverContact,
  receiverChoice,
  receiverForm,
} from '../shared/receivers/receiver';
import { ReceiverField } from '../shared/receivers/receiver-field';
import { REFUSAL_REASON_MAX } from './quote-forms';

export interface QuoteAnswerDialogData {
  kind: 'accept' | 'refuse';
  /**
   * « Reçu par », asked unless the customer's quotes name nobody: whether they must name someone, and the customer's
   * contacts, offered once read. Nobody is named before the answer, so the field opens empty.
   */
  receiver?: {
    required: boolean;
    contacts: Promise<readonly ReceiverContact[]>;
  };
  /** Records the answer; the dialog stays open on a refusal and says it under its field. */
  answer: (answered: QuoteAnswered) => Promise<FormAnswer<unknown>>;
}

/**
 * What the customer answered: the day (empty: the company's today), why they declined, the signed copy, and who
 * answered for them (null: nobody).
 */
export interface QuoteAnswered {
  answeredOn: string;
  refusalReason: string;
  signed: File | null;
  receivedBy: ReceiverChoice | null;
}

/**
 * The customer's answer to a sent quote, asked when it is recorded: the day they gave it, either the copy they signed
 * (« Bon pour accord »), which is attached to the quote, or why they declined, and who answered for them. All are
 * optional unless the customer's quotes require who answered; the day, left empty, is the company's today.
 */
@Component({
  selector: 'app-quote-answer-dialog',
  imports: [
    FormsModule,
    ReactiveFormsModule,
    DayInput,
    DayCalendarButton,
    FileDrop,
    MatButtonModule,
    MatDialogModule,
    MatFormFieldModule,
    MatInputModule,
    ReceiverField,
    RefusedField,
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
            [formControl]="day"
            data-testid="quote-answered-on"
          />
          <app-day-calendar-button matSuffix [field]="dayField" />
          <mat-hint>{{ 'quotes.answer.answered_on_hint' | translate }}</mat-hint>
          <mat-error data-testid="quote-answered-on-error">
            @if (day.getError('refused'); as refused) {
              {{ refused.key | translate: refused.params }}
            } @else {
              {{ 'form.errors.date' | translate }}
            }
          </mat-error>
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
              [appRefused]="(reasonRefused() ?? reasonTooLong()) !== null"
              [ngModel]="reason()"
              (ngModelChange)="reason.set($event); reasonRefused.set(null)"
              data-testid="quote-refusal-reason"
            ></textarea>
            <mat-hint>{{ 'quotes.answer.reason_hint' | translate }}</mat-hint>
          </mat-form-field>
          @if (reasonRefused() ?? reasonTooLong(); as said) {
            <p
              role="alert"
              class="px-4 text-xs text-error"
              data-testid="quote-refusal-reason-error"
            >
              {{ said.key | translate: said.params }}
              @if (said.count) {
                <bdi>{{ said.count }}</bdi>
              }
            </p>
          }
        }
        @if (receiver !== null) {
          <app-receiver-field [form]="receiver" [contacts]="contacts()" testId="quote-receiver" />
        }
        @if (step.unplaced(); as said) {
          <p role="alert" class="text-sm text-error" data-testid="quote-answer-refused">
            {{ said.key | translate: said.params }}
          </p>
        }
      </div>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button type="button" (click)="ref.close(false)" data-testid="quote-answer-keep">
        {{ 'quotes.actions.keep' | translate }}
      </button>
      <button
        mat-flat-button
        type="button"
        [disabled]="step.busy()"
        (click)="answer()"
        data-testid="quote-answer-confirm"
      >
        {{ 'quotes.answer.' + data.kind + '_confirm' | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class QuoteAnswerDialog {
  protected readonly ref = inject<MatDialogRef<QuoteAnswerDialog, boolean>>(MatDialogRef);
  protected readonly data = inject<QuoteAnswerDialogData>(MAT_DIALOG_DATA);
  protected readonly maxBytes = ATTACHMENT_MAX_BYTES;
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;
  /** Empty is the company's today; anything else must be a day, which the day input says (`date`). */
  protected readonly day = new FormControl('', { nonNullable: true });
  protected readonly reason = signal('');
  /** A reason past its limit, kept whole as pasted and said under the field; the answer waits until it fits. */
  protected readonly reasonTooLong = computed(() =>
    lengthSaid(this.reason().trim(), REFUSAL_REASON_MAX),
  );
  protected readonly signed = signal<File | null>(null);
  protected readonly step = new FormStep(inject(TranslateService), inject(RefusalFigures));
  /** What the API refused of the reason, until it changes. */
  protected readonly reasonRefused = signal<FieldError | null>(null);
  /** Who answered, opened empty; null where the customer's quotes name nobody. */
  protected readonly receiver =
    this.data.receiver === undefined ? null : receiverForm(null, this.data.receiver.required);
  protected readonly contacts = signal<readonly ReceiverContact[]>([]);

  constructor() {
    void this.data.receiver?.contacts.then((contacts) => this.contacts.set(contacts));
  }

  protected async answer(): Promise<void> {
    const receiver = this.receiver;
    const fields = receiver === null ? [this.day] : [this.day, receiver];
    if (revealInvalid(fields, this.host) || this.step.busy()) return;
    if (this.data.kind === 'refuse' && this.reasonTooLong() !== null) return;
    const answered: QuoteAnswered = {
      answeredOn: this.day.value,
      refusalReason: this.data.kind === 'refuse' ? this.reason().trim() : '',
      signed: this.data.kind === 'accept' ? this.signed() : null,
      receivedBy: receiver === null ? null : receiverChoice(receiver),
    };
    const answer = await this.step.run(
      () => this.data.answer(answered),
      (refusals) =>
        refusals.filter((refusal) => {
          if (refusal.field === 'answeredOn') {
            this.day.setErrors({ refused: this.step.wordsOf(refusal) });
            this.day.markAsTouched();
            return false;
          }
          if (refusal.field === 'refusalReason' && this.data.kind === 'refuse') {
            this.reasonRefused.set(this.step.wordsOf(refusal));
            return false;
          }
          // Who answered is said under « Reçu par »; asked nowhere, it stays on the line below.
          if (refusal.field === 'receivedBy' && receiver !== null) {
            receiver.controls.name.setErrors({ refused: this.step.wordsOf(refusal) });
            receiver.controls.name.markAsTouched();
            return false;
          }
          return true;
        }),
    );
    if (answer !== 'refused') this.ref.close(answer !== null);
  }
}
