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

export interface PaymentDialogData {
  descriptor: FormDescriptor;
  group: DescriptorFormGroup;
  /** Records the payment; the dialog stays open on a refusal and says it under its field. */
  record: (values: FormValues) => Promise<FormAnswer<unknown>>;
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
      @if (step.unplaced(); as said) {
        <p role="alert" class="text-sm text-error" data-testid="payment-refused">
          {{ said.key | translate: said.params }}
        </p>
      }
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button type="button" (click)="ref.close(false)" data-testid="payment-cancel">
        {{ 'invoices.payments.cancel' | translate }}
      </button>
      <button
        mat-flat-button
        type="button"
        [disabled]="step.busy()"
        (click)="record()"
        data-testid="invoice-payment-record"
      >
        {{ 'invoices.payments.record' | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class PaymentDialog {
  protected readonly ref = inject<MatDialogRef<PaymentDialog, boolean>>(MatDialogRef);
  protected readonly data = inject<PaymentDialogData>(MAT_DIALOG_DATA);
  protected readonly step = new FormStep(inject(TranslateService), inject(RefusalFigures));
  private readonly form = viewChild(DescriptorForm);

  /** Records through the page, which owns the company and the document; closes once kept, or on a failure it says. */
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
