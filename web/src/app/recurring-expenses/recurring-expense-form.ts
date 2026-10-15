// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { TranslatePipe } from '@ngx-translate/core';
import { DescriptorForm } from '../shared/form/descriptor-form';
import type { DescriptorFormGroup } from '../shared/form/form-builder';
import type { FormDescriptor, FormField, FormValues } from '../shared/form/form-types';
import { scheduleFields, scheduleOf } from '../shared/schedule/schedule';
import type {
  RecurringExpenseDraft,
  RecurringExpenseRevision,
  RecurringExpenseRow,
} from './recurring-expenses-types';

const TEXTS = 'expenses.recurring';

/**
 * When to draft, from which day and up to which, and whether the amount changes each time: what making an expense
 * recurring asks. A revision asks the same but the first day, which has passed or is kept.
 */
export function recurringExpenseForm(
  language: string,
  today: string,
  revising: RecurringExpenseRow | null = null,
): FormDescriptor {
  const startsOn = revising?.startsOn ?? today;
  const days: FormField[] = [
    ...(revising === null
      ? [
          {
            id: 'startsOn',
            label: `${TEXTS}.fields.starts_on`,
            kind: 'date' as const,
            required: true,
            hint: `${TEXTS}.fields.starts_on_hint`,
            defaultValue: today,
          },
        ]
      : []),
    {
      id: 'endsOn',
      label: `${TEXTS}.fields.ends_on`,
      kind: 'date',
      hint: `${TEXTS}.fields.ends_on_hint`,
    },
    {
      id: 'variableAmount',
      label: `${TEXTS}.fields.variable_amount`,
      kind: 'checkbox',
      hint: `${TEXTS}.fields.variable_amount_hint`,
      span: 2,
    },
  ];
  return {
    id: revising === null ? 'make-recurring-expense' : 'revise-recurring-expense',
    sections: [
      {
        id: 'schedule',
        title: `${TEXTS}.${revising === null ? 'make' : 'revise'}.title`,
        description: `${TEXTS}.make.intro`,
        fields: [...scheduleFields(language, startsOn), ...days],
      },
    ],
  };
}

function dayOrNull(value: FormValues[string] | undefined): string | null {
  const day = String(value ?? '');
  return day === '' ? null : day;
}

/** What the form holds as the request that makes the expense recurring. */
export function recurringExpenseDraft(
  modelExpenseId: string,
  values: FormValues,
): RecurringExpenseDraft {
  return {
    modelExpenseId,
    schedule: scheduleOf(values),
    startsOn: String(values['startsOn'] ?? ''),
    endsOn: dayOrNull(values['endsOn']),
    variableAmount: values['variableAmount'] === true,
  };
}

/** What the form holds as a revision; pausing is not asked here and stays as it is. */
export function recurringExpenseRevision(
  row: RecurringExpenseRow,
  values: FormValues,
): RecurringExpenseRevision {
  return {
    schedule: scheduleOf(values),
    endsOn: dayOrNull(values['endsOn']),
    variableAmount: values['variableAmount'] === true,
    paused: row.paused,
  };
}

/** The fields a refusal can name, each with its own words; a field with none is the form's as a whole. */
const REFUSED_FIELDS = new Set([
  'modelExpenseId',
  'every',
  'monthDay',
  'ordinal',
  'weekday',
  'startsOn',
  'endsOn',
]);

/** What a refusal says: the field's own words where it has some, else the general line. */
export function recurringExpenseFailure(field: string | null): string {
  return field !== null && REFUSED_FIELDS.has(field)
    ? `${TEXTS}.errors.${field}`
    : `${TEXTS}.errors.invalid`;
}

export interface RecurringExpenseDialogData {
  descriptor: FormDescriptor;
  group: DescriptorFormGroup;
  /** The title and the confirming word: « Rendre récurrente » or « Enregistrer ». */
  title: string;
  confirm: string;
}

/**
 * « Rendre récurrente », asked over the expense, and its revision from the list: each occurrence from the first day on
 * drafts a copy, for a person to check and record; nothing is recorded by itself. The dialog answers with what the
 * form holds; making or revising it is the page's.
 */
@Component({
  selector: 'app-recurring-expense-dialog',
  imports: [MatButtonModule, MatDialogModule, TranslatePipe, DescriptorForm],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title data-testid="recurring-expense-dialog-title">
      {{ data.title | translate }}
    </h2>
    <mat-dialog-content>
      <app-descriptor-form
        [descriptor]="data.descriptor"
        [form]="data.group"
        testId="recurring-expense-form"
        (submitted)="answer()"
      />
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button
        mat-button
        type="button"
        (click)="ref.close(null)"
        data-testid="recurring-expense-dialog-keep"
      >
        {{ 'expenses.recurring.cancel' | translate }}
      </button>
      <button
        mat-flat-button
        type="button"
        (click)="answer()"
        data-testid="recurring-expense-dialog-confirm"
      >
        {{ data.confirm | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class RecurringExpenseDialog {
  protected readonly ref =
    inject<MatDialogRef<RecurringExpenseDialog, FormValues | null>>(MatDialogRef);
  protected readonly data = inject<RecurringExpenseDialogData>(MAT_DIALOG_DATA);

  protected answer(): void {
    if (this.data.group.invalid) {
      this.data.group.markAllAsTouched();
      return;
    }
    this.ref.close(this.data.group.getRawValue() as FormValues);
  }
}
