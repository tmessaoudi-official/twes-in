// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject, viewChild } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import type { FormAnswer } from '../shared/feedback/request-state';
import { DescriptorForm } from '../shared/form/descriptor-form';
import { FormStep } from '../shared/form/form-step';
import { RefusalFigures } from '../shared/form/refusal-figures';
import { AmountPipe, DayPipe, MomentPipe } from '../shared/i18n/format-pipes';
import type { DescriptorFormGroup } from '../shared/form/form-builder';
import type { FormDescriptor, FormValues } from '../shared/form/form-types';
import type { StockMovementListRow } from './inventory-forms';

export interface ReceiptCostDialogData {
  descriptor: FormDescriptor;
  group: DescriptorFormGroup;
  /** The receipt, as the movements list names it: the cost is final once entered, so the question says which it is. */
  receipt: StockMovementListRow;
  /** Enters the cost; the dialog stays open on a refusal and says it under its field. */
  enter: (values: FormValues) => Promise<FormAnswer<unknown>>;
}

/**
 * The cost of a receipt left « à compléter » by someone who could not read costs, asked of a cost reader (docs/SPEC.md
 * § 7, audit 2026-10-06 C challenge 9). It enters them through the movements page and closes once they are kept.
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
      @if (step.unplaced(); as said) {
        <p role="alert" class="text-sm text-error" data-testid="receipt-cost-refused">
          {{ said.key | translate: said.params }}
        </p>
      }
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button type="button" (click)="ref.close(false)" data-testid="receipt-cost-keep">
        {{ 'inventory.receipt_cost.keep' | translate }}
      </button>
      <button
        mat-flat-button
        type="button"
        [disabled]="step.busy()"
        (click)="enter()"
        data-testid="receipt-cost-enter"
      >
        {{ 'inventory.receipt_cost.enter' | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class ReceiptCostDialog {
  protected readonly ref = inject<MatDialogRef<ReceiptCostDialog, boolean>>(MatDialogRef);
  protected readonly data = inject<ReceiptCostDialogData>(MAT_DIALOG_DATA);
  protected readonly receipt = this.data.receipt;
  protected readonly step = new FormStep(inject(TranslateService), inject(RefusalFigures));
  private readonly form = viewChild(DescriptorForm);

  /** Enters through the page, which owns the company; closes once kept, or on a failure the page says. */
  protected async enter(): Promise<void> {
    const group = this.data.group;
    if (group.invalid) {
      group.markAllAsTouched();
      return;
    }
    if (this.step.busy()) return;
    const answer = await this.step.run(
      () => this.data.enter(group.getRawValue()),
      (refusals) => this.form()?.showRefused(refusals) ?? refusals,
    );
    if (answer !== 'refused') this.ref.close(answer !== null);
  }
}
