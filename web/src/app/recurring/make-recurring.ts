// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { TranslatePipe } from '@ngx-translate/core';
import { DescriptorForm } from '../shared/form/descriptor-form';
import type { DescriptorFormGroup } from '../shared/form/form-builder';
import type { FormDescriptor, FormValues } from '../shared/form/form-types';
import {
  RECURRING_FREQUENCIES,
  type RecurringFrequency,
  type RecurringInvoiceDraft,
} from './recurring-types';

/** How often, from which day and up to which: what making an invoice recurring asks. */
export function makeRecurringForm(today: string): FormDescriptor {
  return {
    id: 'make-recurring',
    sections: [
      {
        id: 'schedule',
        title: 'recurring.make.title',
        description: 'recurring.make.intro',
        fields: [
          {
            id: 'frequency',
            label: 'recurring.fields.frequency',
            kind: 'select',
            required: true,
            defaultValue: 'monthly',
            options: RECURRING_FREQUENCIES.map((value) => ({
              value,
              label: `recurring.frequencies.${value}`,
            })),
          },
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
  const frequency = String(values['frequency'] ?? 'monthly');
  const endsOn = String(values['endsOn'] ?? '');
  return {
    modelInvoiceId,
    frequency: (RECURRING_FREQUENCIES as readonly string[]).includes(frequency)
      ? (frequency as RecurringFrequency)
      : 'monthly',
    startsOn: String(values['startsOn'] ?? ''),
    endsOn: endsOn === '' ? null : endsOn,
  };
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
