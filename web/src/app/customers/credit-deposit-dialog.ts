// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { TranslatePipe } from '@ngx-translate/core';
import { DescriptorForm } from '../shared/form/descriptor-form';
import type { DescriptorFormGroup } from '../shared/form/form-builder';
import type { FormDescriptor, FormValues } from '../shared/form/form-types';

export interface CreditDepositDialogData {
  descriptor: FormDescriptor;
  group: DescriptorFormGroup;
}

/**
 * Money received from a customer that no invoice takes yet, asked in a dialog like a payment is. It answers with the
 * values; recording them is the statement's, which owns the company and the customer.
 */
@Component({
  selector: 'app-credit-deposit-dialog',
  imports: [MatButtonModule, MatDialogModule, TranslatePipe, DescriptorForm],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title data-testid="credit-deposit-title">
      {{ 'customers.credit.deposit' | translate }}
    </h2>
    <mat-dialog-content>
      <app-descriptor-form
        [descriptor]="data.descriptor"
        [form]="data.group"
        testId="customer-credit-deposit-form"
        (submitted)="record()"
      />
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button type="button" (click)="ref.close(null)" data-testid="credit-deposit-keep">
        {{ 'customers.credit.keep' | translate }}
      </button>
      <button mat-flat-button type="button" (click)="record()" data-testid="credit-deposit-record">
        {{ 'customers.credit.record' | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class CreditDepositDialog {
  protected readonly ref =
    inject<MatDialogRef<CreditDepositDialog, FormValues | null>>(MatDialogRef);
  protected readonly data = inject<CreditDepositDialogData>(MAT_DIALOG_DATA);

  protected record(): void {
    const group = this.data.group;
    if (group.invalid) {
      group.markAllAsTouched();
      return;
    }
    this.ref.close(group.getRawValue());
  }
}
