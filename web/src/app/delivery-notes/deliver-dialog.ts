// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, DestroyRef, ElementRef, inject } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import {
  type AbstractControl,
  FormControl,
  FormGroup,
  ReactiveFormsModule,
  type ValidationErrors,
} from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import type { FormAnswer } from '../shared/feedback/request-state';
import { DayCalendarButton } from '../shared/form/day-calendar-button';
import { DayInput } from '../shared/form/day-input';
import { FormStep } from '../shared/form/form-step';
import { RefusalFigures } from '../shared/form/refusal-figures';
import { revealInvalid } from '../shared/form/reveal-invalid';
import { formatTime, todayIn } from '../shared/i18n/format';

/**
 * When a note was delivered, asked when delivering rather than sitting as a field in the action bar. It opens on now
 * on the company's clock (its zone is the dialog's data); left as it opened, or emptied, it closes on '' and the API
 * stamps its own now, which a browser clock running ahead cannot push past. Changed, it closes on the moment written,
 * `YYYY-MM-DDTHH:MM` on the company's clock; on `null` only when nothing was asked of it.
 */
/** The locale the field's time is written in: the API's `HH:MM`, whatever the screen's language. */
const WALL_CLOCK = 'en-GB'; // language-literal: its 24-hour HH:MM is the API's format, not a language

export interface DeliverDialogData {
  /** The company's clock, whose today and now the fields open on. */
  zone: string | null;
  /** Marks the note delivered at the moment written, '' for now; the dialog stays open on a refusal. */
  deliver: (moment: string) => Promise<FormAnswer<unknown>>;
}

@Component({
  selector: 'app-deliver-dialog',
  imports: [
    ReactiveFormsModule,
    DayInput,
    DayCalendarButton,
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
      <div class="flex flex-wrap gap-3 pt-2">
        <mat-form-field subscriptSizing="dynamic" class="min-w-48 flex-1">
          <mat-label>{{ 'delivery_notes.actions.delivered_on' | translate }}</mat-label>
          <input
            matInput
            appDay
            #dayField="appDay"
            type="text"
            inputmode="numeric"
            autocomplete="off"
            [formControl]="form.controls.day"
            data-testid="delivery-note-delivered-on"
          />
          <app-day-calendar-button matSuffix [field]="dayField" />
          @if (form.controls.day.getError('refused'); as refused) {
            <mat-error data-testid="delivery-note-delivered-on-error">{{
              refused.key | translate: refused.params
            }}</mat-error>
          } @else if (form.controls.day.hasError('date')) {
            <mat-error data-testid="delivery-note-delivered-on-error">{{
              'form.errors.date' | translate
            }}</mat-error>
          } @else if (form.controls.day.hasError('required')) {
            <mat-error data-testid="delivery-note-delivered-on-error">{{
              'form.errors.required' | translate
            }}</mat-error>
          }
        </mat-form-field>
        <mat-form-field subscriptSizing="dynamic" class="w-32">
          <mat-label>{{ 'delivery_notes.actions.delivered_time' | translate }}</mat-label>
          <input
            matInput
            type="text"
            inputmode="numeric"
            autocomplete="off"
            maxlength="5"
            [formControl]="form.controls.time"
            data-testid="delivery-note-delivered-time"
          />
          @if (form.controls.time.hasError('time')) {
            <mat-error data-testid="delivery-note-delivered-time-error">{{
              'form.errors.time' | translate
            }}</mat-error>
          } @else if (form.controls.time.hasError('required')) {
            <mat-error data-testid="delivery-note-delivered-time-error">{{
              'form.errors.required' | translate
            }}</mat-error>
          }
        </mat-form-field>
      </div>
      @if (step.unplaced(); as said) {
        <p role="alert" class="text-sm text-error" data-testid="deliver-refused">
          {{ said.key | translate: said.params }}
        </p>
      }
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button type="button" (click)="ref.close(false)" data-testid="deliver-cancel">
        {{ 'delivery_notes.actions.keep' | translate }}
      </button>
      <button
        mat-flat-button
        type="button"
        [disabled]="step.busy()"
        (click)="deliver()"
        data-testid="delivery-note-deliver"
      >
        {{ 'delivery_notes.actions.deliver' | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class DeliverDialog {
  protected readonly ref = inject<MatDialogRef<DeliverDialog, boolean>>(MatDialogRef);
  private readonly data = inject<DeliverDialogData>(MAT_DIALOG_DATA);
  private readonly zone = this.data.zone;
  protected readonly step = new FormStep(inject(TranslateService), inject(RefusalFigures));
  private readonly opened = {
    day: todayIn(this.zone),
    time: formatTime(new Date().toISOString(), WALL_CLOCK, this.zone ?? undefined),
  };
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;
  /**
   * Both empty is « now »; otherwise each needs the other. The day's own shape is the day input's to say (`date`), the
   * time's is said here, and each is said under its field once left or once « Livrer » is pressed.
   */
  protected readonly form = new FormGroup({
    day: new FormControl(this.opened.day, {
      nonNullable: true,
      validators: (control) => neededBeside(control, 'time'),
    }),
    time: new FormControl(this.opened.time, {
      nonNullable: true,
      validators: (control) => timeOfDay(control) ?? neededBeside(control, 'day'),
    }),
  });

  constructor() {
    // Each field's need follows what the other holds, so a change to one asks the other again.
    for (const [changed, other] of [
      ['day', 'time'],
      ['time', 'day'],
    ] as const) {
      this.form.controls[changed].valueChanges
        .pipe(takeUntilDestroyed(inject(DestroyRef)))
        .subscribe(() => this.form.controls[other].updateValueAndValidity({ emitEvent: false }));
    }
  }

  protected async deliver(): Promise<void> {
    if (revealInvalid(this.form, this.host) || this.step.busy()) return;
    const answer = await this.step.run(
      () => this.data.deliver(this.moment()),
      // The day the API refuses (`delivered`, before the note or after now) is said under the day.
      (refusals) =>
        refusals.filter((refusal) => {
          if (refusal.field !== 'delivered') return true;
          this.form.controls.day.setErrors({ refused: this.step.wordsOf(refusal) });
          this.form.controls.day.markAsTouched();
          return false;
        }),
    );
    if (answer !== 'refused') this.ref.close(answer !== null);
  }

  /** '' for now, or the moment written. */
  private moment(): string {
    const day = this.form.controls.day.value;
    const time = this.form.controls.time.value.trim();
    const untouched = day === this.opened.day && time === this.opened.time;
    return untouched || (day === '' && time === '') ? '' : `${day}T${wallClock(time)}`;
  }
}

/** A field left empty while the other is filled: a moment needs both its day and its time. */
function neededBeside(control: AbstractControl, other: 'day' | 'time'): ValidationErrors | null {
  const own = String(control.value).trim();
  const beside = String(control.parent?.get(other)?.value ?? '').trim();
  return own === '' && beside !== '' ? { required: true } : null;
}

function timeOfDay(control: AbstractControl): ValidationErrors | null {
  const text = String(control.value).trim();
  return text === '' || wallClock(text) !== null ? null : { time: true };
}

/** A time of day on 24 hours as a person writes it, « 9h05 », « 9:05 » or « 0905 », as `HH:MM`; null otherwise. */
function wallClock(text: string): string | null {
  const match = /^(\d{1,2})[:hH.]?(\d{2})$/.exec(text.trim());
  if (match === null) return null;
  const [hours, minutes] = [Number(match[1]), Number(match[2])];
  return hours < 24 && minutes < 60
    ? `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`
    : null;
}
