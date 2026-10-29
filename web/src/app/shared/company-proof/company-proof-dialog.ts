// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { FormControl, ReactiveFormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { Router } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { CompanyEntryProof, type EntryAsked, type EntryOutcome } from './company-entry-proof';
import { PasswordToggle } from '../form/password-toggle';
import { signInAddress } from '../session/return-to';

/**
 * The step-up's question for entering a company: the password, the authenticator code beside it where the company
 * requires one, or a passkey where the company takes one. A refusal is said in the dialog and leaves it open; the
 * API's budget of attempts is what stops a guess being repeated forever.
 */
@Component({
  selector: 'app-company-proof-dialog',
  imports: [
    PasswordToggle,
    MatButtonModule,
    MatDialogModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    ReactiveFormsModule,
    TranslatePipe,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title data-testid="company-proof-title">
      {{ 'company_proof.title' | translate: { company: asked.companyName } }}
    </h2>
    <form (submit)="$event.preventDefault(); confirmPassword()" novalidate>
      <mat-dialog-content>
        <!-- Material styles mat-dialog-content after Tailwind and sets it back to a block: the layout goes inside.
             Its foot leaves room for the eye's 48 px touch target, which hangs 4 px below the 40 px field. -->
        <div class="flex flex-col gap-3 pb-1">
          <p data-testid="company-proof-intro">{{ 'company_proof.intro' | translate }}</p>
          <mat-form-field>
            <mat-label>{{ 'step_up.password' | translate }}</mat-label>
            <input
              matInput
              #entryPassword
              type="password"
              autocomplete="current-password"
              [formControl]="password"
              data-testid="company-proof-password"
            />
            <app-password-toggle matSuffix [field]="entryPassword" />
          </mat-form-field>
          @if (asked.code) {
            <mat-form-field>
              <mat-label>{{ 'company_proof.code' | translate }}</mat-label>
              <input
                matInput
                type="text"
                inputmode="numeric"
                autocomplete="one-time-code"
                [formControl]="code"
                data-testid="company-proof-code"
              />
            </mat-form-field>
          }
          @if (problem(); as key) {
            <p role="alert" class="text-error" data-testid="company-proof-error">
              {{
                (key === 'code_refused' ? 'company_proof.errors.' : 'step_up.errors.') + key
                  | translate
              }}
            </p>
          }
        </div>
      </mat-dialog-content>
      <mat-dialog-actions align="end">
        <button
          mat-button
          type="button"
          (click)="ref.close(false)"
          data-testid="company-proof-cancel"
        >
          {{ 'step_up.cancel' | translate }}
        </button>
        @if (asked.passkey && proof.passkeySupported()) {
          <button
            mat-stroked-button
            type="button"
            [disabled]="busy()"
            (click)="confirmPasskey()"
            data-testid="company-proof-passkey"
          >
            <mat-icon aria-hidden="true">passkey</mat-icon>
            {{ 'step_up.passkey' | translate }}
          </button>
        }
        <button
          mat-flat-button
          type="submit"
          [disabled]="busy()"
          data-testid="company-proof-confirm"
        >
          {{ 'company_proof.confirm' | translate }}
        </button>
      </mat-dialog-actions>
    </form>
  `,
})
export class CompanyProofDialog {
  protected readonly ref = inject<MatDialogRef<CompanyProofDialog, boolean>>(MatDialogRef);
  protected readonly proof = inject(CompanyEntryProof);
  private readonly router = inject(Router);
  protected readonly asked = inject<EntryAsked>(MAT_DIALOG_DATA);
  protected readonly password = new FormControl('', { nonNullable: true });
  protected readonly code = new FormControl('', { nonNullable: true });
  protected readonly busy = signal(false);
  protected readonly problem = signal<Exclude<EntryOutcome, 'confirmed' | 'signed_out'> | null>(
    null,
  );

  protected async confirmPassword(): Promise<void> {
    if (this.password.value === '') {
      this.problem.set('refused');
      return;
    }
    const code = this.code.value.replace(/\s/g, '');
    if (this.asked.code && code === '') {
      this.problem.set('code_refused');
      return;
    }
    await this.settle(() =>
      this.proof.enterWithPassword(
        this.asked.companyId,
        this.password.value,
        this.asked.code ? code : null,
      ),
    );
  }

  protected async confirmPasskey(): Promise<void> {
    await this.settle(() => this.proof.enterWithPasskey(this.asked.companyId));
  }

  private async settle(proof: () => Promise<EntryOutcome>): Promise<void> {
    if (this.busy()) return;
    this.busy.set(true);
    this.problem.set(null);
    try {
      const outcome = await proof();
      if (outcome === 'confirmed') this.ref.close(true);
      else if (outcome === 'signed_out') {
        // The session is over: the sign-in says why.
        this.ref.close(false);
        void this.router.navigateByUrl(signInAddress(this.router.url, true));
      } else this.problem.set(outcome);
    } finally {
      this.busy.set(false);
    }
  }
}
