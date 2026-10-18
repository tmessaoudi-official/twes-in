// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { TranslatePipe } from '@ngx-translate/core';
import { DescriptorForm } from '../shared/form/descriptor-form';
import { AmountPipe, DayPipe, MomentPipe } from '../shared/i18n/format-pipes';
import type { DescriptorFormGroup } from '../shared/form/form-builder';
import type { FormDescriptor, FormValues } from '../shared/form/form-types';
import type { StockMovementListRow } from './inventory-forms';

export interface ReceiptCostDialogData {
  descriptor: FormDescriptor;
  group: DescriptorFormGroup;
  /** The receipt, as the movements list names it: the cost is final once entered, so the question says which it is. */
  receipt: StockMovementListRow;
}

/**
 * The cost of a receipt left « à compléter » by someone who could not read costs, asked of a cost reader (docs/SPEC.md
 * § 7, audit 2026-10-06 C challenge 9). It answers with the values; entering them is the movements page's.
 */
@Component({
  selector: 'app-receipt-cost-dialog',
  imports: [
    MatButtonModule,
    MatDialogModule,
    TranslatePipe,
    DescriptorForm,
    AmountPipe,
    DayPipe,
    MomentPipe,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title data-testid="receipt-cost-title">
      {{ 'inventory.receipt_cost.title' | translate }}
    </h2>
    <mat-dialog-content>
      <p class="font-semibold" data-testid="receipt-cost-product">
        <bdi>{{ receipt.productLabel }}</bdi>
      </p>
      <dl class="mb-4 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
        <dt>{{ 'inventory.stock.fields.quantity' | translate }}</dt>
        <dd data-testid="receipt-cost-quantity">
          {{ receipt.quantity | amount: receipt.unitDecimals }}
        </dd>
        <dt>{{ 'inventory.stock.fields.at' | translate }}</dt>
        <dd data-testid="receipt-cost-at">{{ receipt.at | moment }}</dd>
        <dt>{{ 'inventory.stock.fields.location' | translate }}</dt>
        <dd data-testid="receipt-cost-location">
          <bdi>{{ receipt.locationLabel }}</bdi>
        </dd>
        @if (receipt.vendorName; as vendor) {
          <dt>{{ 'inventory.stock.fields.vendor' | translate }}</dt>
          <dd data-testid="receipt-cost-vendor">
            {{ vendor }}
            @if (receipt.supplierReference; as reference) {
              — {{ reference }}
            }
          </dd>
        }
        @if (receipt.receivedOn; as day) {
          <dt>{{ 'inventory.stock.fields.receivedOn' | translate }}</dt>
          <dd data-testid="receipt-cost-received">{{ day | day }}</dd>
        }
      </dl>
      <p class="mb-4" data-testid="receipt-cost-intro">
        {{ 'inventory.receipt_cost.intro' | translate }}
      </p>
      <app-descriptor-form
        [descriptor]="data.descriptor"
        [form]="data.group"
        testId="receipt-cost-form"
        (submitted)="enter()"
      />
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button type="button" (click)="ref.close(null)" data-testid="receipt-cost-keep">
        {{ 'inventory.receipt_cost.keep' | translate }}
      </button>
      <button mat-flat-button type="button" (click)="enter()" data-testid="receipt-cost-enter">
        {{ 'inventory.receipt_cost.enter' | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class ReceiptCostDialog {
  protected readonly ref = inject<MatDialogRef<ReceiptCostDialog, FormValues | null>>(MatDialogRef);
  protected readonly data = inject<ReceiptCostDialogData>(MAT_DIALOG_DATA);
  protected readonly receipt = this.data.receipt;

  protected enter(): void {
    const group = this.data.group;
    if (group.invalid) {
      group.markAllAsTouched();
      return;
    }
    this.ref.close(group.getRawValue());
  }
}
