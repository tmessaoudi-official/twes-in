// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { TranslatePipe } from '@ngx-translate/core';
import { DescriptorForm } from '../shared/form/descriptor-form';
import type { DescriptorFormGroup } from '../shared/form/form-builder';
import type { FormDescriptor, FormValues } from '../shared/form/form-types';
import { scheduleFailure, scheduleFields, scheduleOf } from '../shared/schedule/schedule';
import type { RecurringInvoiceDraft } from './recurring-types';

/**
 * When, from which day and up to which: what making an invoice recurring asks. When is the shared schedule (docs/SPEC.md
 * § 7, 2026-10-15 17:46): any interval, a month or a year on a date, its last day or one of its weekdays.
 */
export function makeRecurringForm(locale: string, today: string): FormDescriptor {
  return {
    id: 'make-recurring',
    sections: [
      {
        id: 'schedule',
        title: 'recurring.make.title',
        description: 'recurring.make.intro',
        fields: [
          ...scheduleFields(locale, today),
          {
            id: 'startsOn',
            label: 'recurring.fields.starts_on',
            kind: 'date',
            required: true,
            hint: 'recurring.fields.starts_on_hint',
            defaultValue: today,
          },
          {
            id: 'endsOn',
            label: 'recurring.fields.ends_on',
            kind: 'date',
            hint: 'recurring.fields.ends_on_hint',
          },
        ],
      },
    ],
  };
}

/** What the form holds as the request that makes the invoice recurring. */
export function recurringDraft(modelInvoiceId: string, values: FormValues): RecurringInvoiceDraft {
  const endsOn = String(values['endsOn'] ?? '');
  return {
    modelInvoiceId,
    schedule: scheduleOf(values),
    startsOn: String(values['startsOn'] ?? ''),
    endsOn: endsOn === '' ? null : endsOn,
  };
}

/** The fields a refusal can name with words of the recurring invoices' own; the schedule's have theirs. */
const REFUSED_FIELDS = new Set(['modelInvoiceId', 'startsOn', 'endsOn']);

/** What a refusal says: the field's own words where it has some, the schedule's for its own, else the general line. */
export function recurringFailure(field: string | null): string {
  return (
    scheduleFailure(field) ??
    (field !== null && REFUSED_FIELDS.has(field)
      ? `recurring.errors.${field}`
      : 'recurring.errors.invalid')
  );
}

export interface MakeRecurringData {
  descriptor: FormDescriptor;
  group: DescriptorFormGroup;
}

/**
 * « Rendre récurrente », asked over the invoice: each occurrence from the first day on drafts a copy of it, for a
 * person to issue; nothing is issued by itself. The dialog answers with what the form holds; making it is the page's.
 */
@Component({
  selector: 'app-make-recurring-dialog',
  imports: [MatButtonModule, MatDialogModule, TranslatePipe, DescriptorForm],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title data-testid="make-recurring-title">
      {{ 'recurring.make.action' | translate }}
    </h2>
    <mat-dialog-content>
      <app-descriptor-form
        [descriptor]="data.descriptor"
        [form]="data.group"
        testId="make-recurring-form"
        (submitted)="make()"
      />
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button type="button" (click)="ref.close(null)" data-testid="make-recurring-keep">
        {{ 'recurring.cancel' | translate }}
      </button>
      <button mat-flat-button type="button" (click)="make()" data-testid="make-recurring-confirm">
        {{ 'recurring.make.confirm' | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class MakeRecurringDialog {
  protected readonly ref =
    inject<MatDialogRef<MakeRecurringDialog, FormValues | null>>(MatDialogRef);
  protected readonly data = inject<MakeRecurringData>(MAT_DIALOG_DATA);

  protected make(): void {
    if (this.data.group.invalid) {
      this.data.group.markAllAsTouched();
      return;
    }
    this.ref.close(this.data.group.getRawValue() as FormValues);
  }
}
