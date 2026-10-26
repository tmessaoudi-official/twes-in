// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject, viewChild } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import type { FormAnswer } from '../shared/feedback/request-state';
import { DescriptorForm } from '../shared/form/descriptor-form';
import { FormStep } from '../shared/form/form-step';
import type { DescriptorFormGroup } from '../shared/form/form-builder';
import type { FormDescriptor, FormValues } from '../shared/form/form-types';
import { RefusalFigures } from '../shared/form/refusal-figures';

export interface OverpaymentDialogData {
  descriptor: FormDescriptor;
  group: DescriptorFormGroup;
  /** Records it; the dialog stays open on a refusal and says it under its field. */
  record: (values: FormValues) => Promise<FormAnswer<unknown>>;
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
      @if (step.unplaced(); as said) {
        <p role="alert" class="text-sm text-error" data-testid="overpayment-refused">
          {{ said.key | translate: said.params }}
        </p>
      }
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button type="button" (click)="ref.close(false)" data-testid="overpayment-keep">
        {{ 'invoices.overpayment.keep' | translate }}
      </button>
      <button
        mat-flat-button
        type="button"
        [disabled]="step.busy()"
        (click)="record()"
        data-testid="overpayment-record"
      >
        {{ 'invoices.overpayment.record' | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class OverpaymentDialog {
  protected readonly ref = inject<MatDialogRef<OverpaymentDialog, boolean>>(MatDialogRef);
  protected readonly data = inject<OverpaymentDialogData>(MAT_DIALOG_DATA);
  protected readonly step = new FormStep(inject(TranslateService), inject(RefusalFigures));
  private readonly form = viewChild(DescriptorForm);

  protected async record(): Promise<void> {
    const group = this.data.group;
    if (group.invalid) {
      group.markAllAsTouched();
      return;
    }
    if (this.step.busy()) return;
    const answer = await this.step.run(
      () => this.data.record(group.getRawValue()),
      (refusals) => this.form()?.showRefused(refusals) ?? refusals,
    );
    if (answer !== 'refused') this.ref.close(answer !== null);
  }
}
