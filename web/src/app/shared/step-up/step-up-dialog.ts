// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { TranslatePipe } from '@ngx-translate/core';
import { StepUpProof } from './step-up-proof';
import { ProofForm } from './proof-form';

/** What the dialog is opened with: the translation key of the sentence saying why it asks. */
export interface StepUpReason {
  intro: string;
}

/**
 * The question behind a step-up, asked by the one proof form a company's entry asks with too: the password, the
 * authenticator code beside it where a second factor is required of the account, or one of its passkeys.
 */
@Component({
  selector: 'app-step-up-dialog',
  imports: [MatDialogModule, ProofForm, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title data-testid="step-up-title">{{ 'step_up.title' | translate }}</h2>
    <app-proof-form
      testIds="step-up"
      confirmKey="step_up.confirm"
      [asked]="proof.proofAsked()"
      [passkeySupported]="proof.passkeySupported()"
      [byPassword]="byPassword"
      [byPasskey]="byPasskey"
      (closed)="ref.close($event)"
    >
      <p data-testid="step-up-intro">{{ intro | translate }}</p>
    </app-proof-form>
  `,
})
export class StepUpDialog {
  protected readonly ref = inject<MatDialogRef<StepUpDialog, boolean>>(MatDialogRef);
  protected readonly proof = inject(StepUpProof);
  /** Why the proof is asked for, as a translation key: leaving customer view unless the caller says otherwise. */
  protected readonly intro =
    inject<StepUpReason | undefined>(MAT_DIALOG_DATA, { optional: true })?.intro ?? 'step_up.intro';
  protected readonly byPassword = (password: string, code: string | null) =>
    this.proof.withPassword(password, code);
  protected readonly byPasskey = () => this.proof.withPasskey();
}
