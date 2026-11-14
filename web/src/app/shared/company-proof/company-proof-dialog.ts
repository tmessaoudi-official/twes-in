// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { TranslatePipe } from '@ngx-translate/core';
import { CompanyEntryProof, type EntryAsked } from './company-entry-proof';
import { ProofForm } from '../step-up/proof-form';

/**
 * The question for entering a company, asked by the step-up's one proof form: the password, the authenticator code
 * beside it where the company requires one, or a passkey where the company takes one.
 */
@Component({
  selector: 'app-company-proof-dialog',
  imports: [MatDialogModule, ProofForm, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title data-testid="company-proof-title">
      {{ 'company_proof.title' | translate: { company: asked.companyName } }}
    </h2>
    <app-proof-form
      testIds="company-proof"
      confirmKey="company_proof.confirm"
      [asked]="asked"
      [passkeySupported]="proof.passkeySupported()"
      [byPassword]="byPassword"
      [byPasskey]="byPasskey"
      (closed)="ref.close($event)"
    >
      <p data-testid="company-proof-intro">{{ 'company_proof.intro' | translate }}</p>
    </app-proof-form>
  `,
})
export class CompanyProofDialog {
  protected readonly ref = inject<MatDialogRef<CompanyProofDialog, boolean>>(MatDialogRef);
  protected readonly proof = inject(CompanyEntryProof);
  protected readonly asked = inject<EntryAsked>(MAT_DIALOG_DATA);
  protected readonly byPassword = (password: string, code: string | null) =>
    this.proof.enterWithPassword(this.asked.companyId, password, code);
  protected readonly byPasskey = () => this.proof.enterWithPasskey(this.asked.companyId);
}
