// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { TranslatePipe } from '@ngx-translate/core';
import { DescriptorForm } from '../shared/form/descriptor-form';
import type { DescriptorFormGroup } from '../shared/form/form-builder';
import type { FormDescriptor, FormValues } from '../shared/form/form-types';

export interface OverpaymentDialogData {
  descriptor: FormDescriptor;
  group: DescriptorFormGroup;
}

/**
 * Money the customer paid beyond an invoice already paid in full, asked in a dialog like a payment is. It answers with
 * the values; recording them is the invoice page's, which owns the company and the invoice.
 */
@Component({
  selector: 'app-overpayment-dialog',
  imports: [MatButtonModule, MatDialogModule, TranslatePipe, DescriptorForm],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title data-testid="overpayment-title">
      {{ 'invoices.overpayment.title' | translate }}
    </h2>
    <mat-dialog-content>
      <p class="text-sm">{{ 'invoices.overpayment.hint' | translate }}</p>
      <app-descriptor-form
        [descriptor]="data.descriptor"
        [form]="data.group"
        testId="invoice-overpayment-form"
        (submitted)="record()"
      />
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button type="button" (click)="ref.close(null)" data-testid="overpayment-keep">
        {{ 'invoices.overpayment.keep' | translate }}
      </button>
      <button mat-flat-button type="button" (click)="record()" data-testid="overpayment-record">
        {{ 'invoices.overpayment.record' | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class OverpaymentDialog {
  protected readonly ref = inject<MatDialogRef<OverpaymentDialog, FormValues | null>>(MatDialogRef);
  protected readonly data = inject<OverpaymentDialogData>(MAT_DIALOG_DATA);

  protected record(): void {
    const group = this.data.group;
    if (group.invalid) {
      group.markAllAsTouched();
      return;
    }
    this.ref.close(group.getRawValue());
  }
}
