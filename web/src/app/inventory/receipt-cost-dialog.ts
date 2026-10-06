// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { TranslatePipe } from '@ngx-translate/core';
import { DescriptorForm } from '../shared/form/descriptor-form';
import type { DescriptorFormGroup } from '../shared/form/form-builder';
import type { FormDescriptor, FormValues } from '../shared/form/form-types';

export interface ReceiptCostDialogData {
  descriptor: FormDescriptor;
  group: DescriptorFormGroup;
  /** What came in, as the movements list names it. */
  product: string;
}

/**
 * The cost of a receipt left « à compléter » by someone who could not read costs, asked of a cost reader (docs/SPEC.md
 * § 7, audit 2026-10-06 C challenge 9). It answers with the values; entering them is the movements page's.
 */
@Component({
  selector: 'app-receipt-cost-dialog',
  imports: [MatButtonModule, MatDialogModule, TranslatePipe, DescriptorForm],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title data-testid="receipt-cost-title">
      {{ 'inventory.receipt_cost.title' | translate }}
    </h2>
    <mat-dialog-content>
      @if (data.product) {
        <p class="font-semibold" data-testid="receipt-cost-product">{{ data.product }}</p>
      }
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

  protected enter(): void {
    const group = this.data.group;
    if (group.invalid) {
      group.markAllAsTouched();
      return;
    }
    this.ref.close(group.getRawValue());
  }
}
