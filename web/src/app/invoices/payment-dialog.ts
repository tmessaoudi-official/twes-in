// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { TranslatePipe } from '@ngx-translate/core';
import { DescriptorForm } from '../shared/form/descriptor-form';
import type { DescriptorFormGroup } from '../shared/form/form-builder';
import type { FormDescriptor, FormValues } from '../shared/form/form-types';

export interface PaymentDialogData {
  descriptor: FormDescriptor;
  group: DescriptorFormGroup;
}

/**
 * Recording a payment (design review finding 3). It was a form at the foot of the page, below the lines and the
 * totals, which is where "Enregistrer le paiement" was found sitting below 2000 px of form; a payment is one
 * answer to one question, so it is asked in a dialog.
 */
@Component({
  selector: 'app-payment-dialog',
  imports: [MatButtonModule, MatDialogModule, TranslatePipe, DescriptorForm],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title data-testid="payment-dialog-title">
      {{ 'invoices.payments.record' | translate }}
    </h2>
    <mat-dialog-content>
      <app-descriptor-form
        [descriptor]="data.descriptor"
        [form]="data.group"
        testId="invoice-payment-form"
        (submitted)="record()"
      />
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button type="button" (click)="ref.close(null)" data-testid="payment-cancel">
        {{ 'invoices.payments.keep' | translate }}
      </button>
      <button mat-flat-button type="button" (click)="record()" data-testid="invoice-payment-record">
        {{ 'invoices.payments.record' | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class PaymentDialog {
  protected readonly ref = inject<MatDialogRef<PaymentDialog, FormValues | null>>(MatDialogRef);
  protected readonly data = inject<PaymentDialogData>(MAT_DIALOG_DATA);

  /** The dialog answers with the values; recording them is the page's, which owns the company and the document. */
  protected record(): void {
    const group = this.data.group;
    if (group.invalid) {
      group.markAllAsTouched();
      return;
    }
    this.ref.close(group.getRawValue());
  }
}
