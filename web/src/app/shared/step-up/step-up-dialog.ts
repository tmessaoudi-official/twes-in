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
import { type StepUpOutcome, StepUpProof } from './step-up-proof';

/** What the dialog is opened with: the translation key of the sentence saying why it asks. */
export interface StepUpReason {
  intro: string;
}

/**
 * The question behind a step-up: the password, or a passkey where the browser has one. A refusal is said in the
 * dialog and leaves it open, so a mistyped password is tried again; the API's own budget of attempts is what stops
 * a guess being repeated forever.
 */
@Component({
  selector: 'app-step-up-dialog',
  imports: [
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
    <h2 mat-dialog-title data-testid="step-up-title">{{ 'step_up.title' | translate }}</h2>
    <form (submit)="$event.preventDefault(); confirmPassword()" novalidate>
      <mat-dialog-content class="flex flex-col gap-3">
        <p data-testid="step-up-intro">{{ intro | translate }}</p>
        <mat-form-field>
          <mat-label>{{ 'step_up.password' | translate }}</mat-label>
          <input
            matInput
            type="password"
            autocomplete="current-password"
            [formControl]="password"
            data-testid="step-up-password"
          />
        </mat-form-field>
        @if (problem(); as key) {
          <p role="alert" class="text-error" data-testid="step-up-error">
            {{ 'step_up.errors.' + key | translate }}
          </p>
        }
      </mat-dialog-content>
      <mat-dialog-actions align="end">
        <button mat-button type="button" (click)="ref.close(false)" data-testid="step-up-cancel">
          {{ 'step_up.cancel' | translate }}
        </button>
        @if (proof.passkeySupported()) {
          <button
            mat-stroked-button
            type="button"
            [disabled]="busy()"
            (click)="confirmPasskey()"
            data-testid="step-up-passkey"
          >
            <mat-icon aria-hidden="true">passkey</mat-icon>
            {{ 'step_up.passkey' | translate }}
          </button>
        }
        <button mat-flat-button type="submit" [disabled]="busy()" data-testid="step-up-confirm">
          {{ 'step_up.confirm' | translate }}
        </button>
      </mat-dialog-actions>
    </form>
  `,
})
export class StepUpDialog {
  protected readonly ref = inject<MatDialogRef<StepUpDialog, boolean>>(MatDialogRef);
  protected readonly proof = inject(StepUpProof);
  private readonly router = inject(Router);
  /** Why the proof is asked for, as a translation key: leaving customer view unless the caller says otherwise. */
  protected readonly intro =
    inject<StepUpReason | undefined>(MAT_DIALOG_DATA, { optional: true })?.intro ?? 'step_up.intro';
  protected readonly password = new FormControl('', { nonNullable: true });
  protected readonly busy = signal(false);
  protected readonly problem = signal<Exclude<StepUpOutcome, 'confirmed' | 'signed_out'> | null>(
    null,
  );

  protected async confirmPassword(): Promise<void> {
    if (this.password.value === '') {
      this.problem.set('refused');
      return;
    }
    await this.settle(() => this.proof.withPassword(this.password.value));
  }

  protected async confirmPasskey(): Promise<void> {
    await this.settle(() => this.proof.withPasskey());
  }

  private async settle(proof: () => Promise<StepUpOutcome>): Promise<void> {
    if (this.busy()) return;
    this.busy.set(true);
    this.problem.set(null);
    try {
      const outcome = await proof();
      if (outcome === 'confirmed') this.ref.close(true);
      else if (outcome === 'signed_out') {
        // The session is over: what the dialog guarded is gone with it, so the sign-in says why.
        this.ref.close(false);
        void this.router.navigateByUrl('/login?expired=1');
      } else this.problem.set(outcome);
    } finally {
      this.busy.set(false);
    }
  }
}
