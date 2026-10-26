// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject, viewChild } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import type { FormAnswer } from '../shared/feedback/request-state';
import { DescriptorForm } from '../shared/form/descriptor-form';
import { FormStep } from '../shared/form/form-step';
import type { DescriptorFormGroup } from '../shared/form/form-builder';
import type { FormDescriptor } from '../shared/form/form-types';
import { RefusalFigures } from '../shared/form/refusal-figures';

export interface CreditNoteDialogData {
  descriptor: FormDescriptor;
  group: DescriptorFormGroup;
  /** Drafts the credit note stating why; the dialog stays open on a refusal and says it under its field. */
  draft: (reason: string) => Promise<FormAnswer<{ readonly id: string }>>;
}

/**
 * Why a credit note corrects its invoice, asked before it is drafted: the reason is required when it is created and
 * printed on it with the invoice's number and date (docs/SPEC.md § 7, 2026-09-24 22:51, EN 16931 BG-3). The dialog
 * answers with the reason, trimmed; a reason of spaces alone is refused here as the API would refuse it.
 */
@Component({
  selector: 'app-credit-note-dialog',
  imports: [MatButtonModule, MatDialogModule, TranslatePipe, DescriptorForm],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title data-testid="credit-note-dialog-title">
      {{ 'invoices.actions.credit_note' | translate }}
    </h2>
    <mat-dialog-content>
      <app-descriptor-form
        [descriptor]="data.descriptor"
        [form]="data.group"
        testId="invoice-credit-note-form"
        (submitted)="create()"
      />
      @if (step.unplaced(); as said) {
        <p role="alert" class="text-sm text-error" data-testid="credit-note-refused">
          {{ said.key | translate: said.params }}
        </p>
      }
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button type="button" (click)="ref.close(null)" data-testid="credit-note-keep">
        {{ 'invoices.credit_note.keep' | translate }}
      </button>
      <button
        mat-flat-button
        type="button"
        [disabled]="step.busy()"
        (click)="create()"
        data-testid="credit-note-create"
      >
        {{ 'invoices.actions.credit_note' | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class CreditNoteDialog {
  protected readonly ref = inject<MatDialogRef<CreditNoteDialog, string | null>>(MatDialogRef);
  protected readonly data = inject<CreditNoteDialogData>(MAT_DIALOG_DATA);
  protected readonly step = new FormStep(inject(TranslateService), inject(RefusalFigures));
  private readonly form = viewChild(DescriptorForm);

  /** Drafts through the page, which owns the company and the invoice; answers the credit note's id once drafted. */
  protected async create(): Promise<void> {
    const group = this.data.group;
    const reason = String(group.getRawValue()['reason'] ?? '').trim();
    if (reason === '') group.controls['reason']?.setValue('');
    if (group.invalid) {
      group.markAllAsTouched();
      return;
    }
    if (this.step.busy()) return;
    const answer = await this.step.run(
      () => this.data.draft(reason),
      (refusals) => this.form()?.showRefused(refusals) ?? refusals,
    );
    if (answer !== 'refused') this.ref.close(answer?.kept.id ?? null);
  }
}
