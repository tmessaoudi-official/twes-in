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
import { formatTime, todayIn } from '../shared/i18n/format';

/**
 * When a note was delivered, asked when delivering rather than sitting as a field in the action bar. It opens on now
 * on the company's clock (its zone is the dialog's data); left as it opened, or emptied, it closes on '' and the API
 * stamps its own now, which a browser clock running ahead cannot push past. Changed, it closes on the moment written,
 * `YYYY-MM-DDTHH:MM` on the company's clock; on `null` only when nothing was asked of it.
 */
/** The locale the field's time is written in: the API's `HH:MM`, whatever the screen's language. */
const WALL_CLOCK = 'en-GB'; // language-literal: its 24-hour HH:MM is the API's format, not a language

@Component({
  selector: 'app-deliver-dialog',
  imports: [
    FormsModule,
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
            [ngModel]="day()"
            (ngModelChange)="day.set($event)"
            data-testid="delivery-note-delivered-on"
          />
          <app-day-calendar-button matSuffix [field]="dayField" />
        </mat-form-field>
        <mat-form-field subscriptSizing="dynamic" class="w-32">
          <mat-label>{{ 'delivery_notes.actions.delivered_time' | translate }}</mat-label>
          <input
            matInput
            type="text"
            inputmode="numeric"
            autocomplete="off"
            maxlength="5"
            [ngModel]="time()"
            (ngModelChange)="time.set($event)"
            data-testid="delivery-note-delivered-time"
          />
        </mat-form-field>
      </div>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button type="button" (click)="ref.close(null)" data-testid="deliver-cancel">
        {{ 'delivery_notes.actions.keep' | translate }}
      </button>
      <button
        mat-flat-button
        type="button"
        [disabled]="!valid()"
        (click)="ref.close(moment())"
        data-testid="delivery-note-deliver"
      >
        {{ 'delivery_notes.actions.deliver' | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class DeliverDialog {
  protected readonly ref = inject<MatDialogRef<DeliverDialog, string | null>>(MatDialogRef);
  private readonly zone = inject<string | null>(MAT_DIALOG_DATA);
  private readonly opened = {
    day: todayIn(this.zone),
    time: formatTime(new Date().toISOString(), WALL_CLOCK, this.zone ?? undefined),
  };
  protected readonly day = signal(this.opened.day);
  protected readonly time = signal(this.opened.time);

  /** The time as the API takes it, `HH:MM`, from what was typed; null when it is not a time of day. */
  private readonly clock = computed(() => wallClock(this.time()));

  protected readonly valid = computed(
    () =>
      (this.day() === '' && this.time().trim() === '') ||
      (/^\d{4}-\d{2}-\d{2}$/.test(this.day()) && this.clock() !== null),
  );

  /** '' for now, or the moment written. */
  protected moment(): string {
    const [day, time] = [this.day(), this.time().trim()];
    const untouched = day === this.opened.day && time === this.opened.time;
    return untouched || (day === '' && time === '') ? '' : `${day}T${this.clock()}`;
  }
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
