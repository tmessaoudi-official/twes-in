// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  afterNextRender,
  ChangeDetectionStrategy,
  Component,
  type ElementRef,
  computed,
  inject,
  Injector,
  input,
  output,
  signal,
  viewChild,
} from '@angular/core';
import { FormControl, ReactiveFormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatDialogModule } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { Router } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import type { ProofAsked, StepUpOutcome } from './step-up-proof';
import { PasswordToggle } from '../form/password-toggle';
import { signInAddress } from '../session/return-to';
import { typedCode } from '../form/typed-code';
import { SpentBudget } from '../errors/spent-budget';
import { typeAgain } from './type-again';
import { RefusalPipe } from '../errors/refusal-pipe';

/**
 * The one question behind every proof of who is at the screen, a step-up's and a company's entry: the password, the
 * authenticator code beside it where it is asked, or a passkey where the account holds one, as the API says (`asked`).
 * A refusal is said in the form and leaves it open, so a mistyped answer is tried again; the API's budget of attempts is
 * what stops a guess being repeated forever. Where the API asks more than the session read said (a company of the
 * account began requiring a second factor since), its refusal opens the code field or offers the passkey.
 *
 * The dialog around it gives the title, the sentence saying why it asks (projected), the confirm button's words, and the
 * prefix of its test ids, so each dialog keeps its own.
 */
@Component({
  selector: 'app-proof-form',
  imports: [
    RefusalPipe,
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
  host: { class: 'block' },
  template: `
    <form (submit)="$event.preventDefault(); confirmPassword()" novalidate>
      <mat-dialog-content>
        <!-- Material styles mat-dialog-content after Tailwind and sets it back to a block: the layout goes inside.
             Its foot leaves room for the eye's 48 px touch target, which hangs 4 px below the 40 px field. -->
        <div class="flex flex-col gap-3 pb-1">
          <ng-content />
          @if (!asked().password) {
            <p [attr.data-testid]="testIds() + '-passkey-only'">
              {{
                (passkeySupported() ? 'step_up.passkey_only' : 'step_up.passkey_unavailable')
                  | translate
              }}
            </p>
          } @else {
            <mat-form-field>
              <mat-label>{{ 'step_up.password' | translate }}</mat-label>
              <input
                matInput
                #proofPassword
                type="password"
                autocomplete="current-password"
                [formControl]="password"
                [attr.data-testid]="testIds() + '-password'"
              />
              <app-password-toggle matSuffix [field]="proofPassword" />
            </mat-form-field>
          }
          @if (asked().password && codeAsked()) {
            <mat-form-field>
              <mat-label>{{ 'step_up.code' | translate }}</mat-label>
              <input
                matInput
                type="text"
                inputmode="numeric"
                autocomplete="one-time-code"
                #proofCode
                [formControl]="code"
                [attr.data-testid]="testIds() + '-code'"
              />
            </mat-form-field>
          }
          @if (problem(); as key) {
            <p role="alert" class="text-error" [attr.data-testid]="testIds() + '-error'">
              {{ key | refusal: 'step_up.errors' : { retry: budget.retry() } }}
            </p>
          }
        </div>
      </mat-dialog-content>
      <mat-dialog-actions align="end">
        <button
          mat-button
          type="button"
          (click)="closed.emit(false)"
          [attr.data-testid]="testIds() + '-cancel'"
        >
          {{ 'step_up.cancel' | translate }}
        </button>
        @if (passkeyAsked() && passkeySupported()) {
          <button
            mat-stroked-button
            type="button"
            [disabled]="busy()"
            (click)="confirmPasskey()"
            [attr.data-testid]="testIds() + '-passkey'"
          >
            <mat-icon aria-hidden="true">passkey</mat-icon>
            {{ 'step_up.passkey' | translate }}
          </button>
        }
        @if (asked().password) {
          <button
            mat-flat-button
            type="submit"
            [disabled]="busy()"
            [attr.data-testid]="testIds() + '-confirm'"
          >
            {{ confirmKey() | translate }}
          </button>
        }
      </mat-dialog-actions>
    </form>
  `,
})
export class ProofForm {
  /** What the API says this proof takes. */
  readonly asked = input.required<ProofAsked>();
  /** The prefix of every test id, the dialog's own (`step-up`, `company-proof`). */
  readonly testIds = input.required<string>();
  /** The confirm button's words, as a translation key. */
  readonly confirmKey = input.required<string>();
  readonly passkeySupported = input.required<boolean>();
  readonly byPassword =
    input.required<(password: string, code: string | null) => Promise<StepUpOutcome>>();
  readonly byPasskey = input.required<() => Promise<StepUpOutcome>>();
  /** true once proved, false when given up or once the session ended. */
  readonly closed = output<boolean>();

  /** When a spent budget may be tried again, which its refusal says. */
  protected readonly budget = inject(SpentBudget);
  private readonly router = inject(Router);
  private readonly injector = inject(Injector);
  protected readonly password = new FormControl('', { nonNullable: true });
  protected readonly code = new FormControl('', { nonNullable: true });
  private readonly passwordInput = viewChild<ElementRef<HTMLInputElement>>('proofPassword');
  private readonly codeInput = viewChild<ElementRef<HTMLInputElement>>('proofCode');
  protected readonly busy = signal(false);
  protected readonly problem = signal<Exclude<StepUpOutcome, 'confirmed' | 'signed_out'> | null>(
    null,
  );
  /** The code field, where the API asks it or refused an answer without it. */
  protected readonly codeAsked = computed(
    () => this.asked().code || this.problem() === 'code_refused',
  );
  /** The passkey, where the account holds one or the API answered that only it proves the account. */
  protected readonly passkeyAsked = computed(
    () => this.asked().passkey || this.problem() === 'passkey_required',
  );

  protected async confirmPassword(): Promise<void> {
    if (this.password.value === '') {
      this.problem.set('refused');
      typeAgain(this.passwordInput());
      return;
    }
    const code = typedCode(this.code.value);
    if (this.codeAsked() && code === '') {
      this.problem.set('code_refused');
      typeAgain(this.codeInput());
      return;
    }
    await this.settle(() => this.byPassword()(this.password.value, this.codeAsked() ? code : null));
    if (this.problem() === 'refused') typeAgain(this.passwordInput());
    if (this.problem() === 'code_refused') {
      const field = this.codeInput();
      // A field the refusal has just opened is drawn on the next render: the cursor goes there once it is.
      if (field) typeAgain(field);
      else afterNextRender(() => typeAgain(this.codeInput()), { injector: this.injector });
    }
  }

  protected async confirmPasskey(): Promise<void> {
    await this.settle(() => this.byPasskey()());
  }

  private async settle(proof: () => Promise<StepUpOutcome>): Promise<void> {
    if (this.busy()) return;
    this.busy.set(true);
    this.problem.set(null);
    try {
      const outcome = await proof();
      if (outcome === 'confirmed') this.closed.emit(true);
      else if (outcome === 'signed_out') {
        // The session is over: what the proof guarded is gone with it, so the sign-in says why.
        this.closed.emit(false);
        void this.router.navigateByUrl(signInAddress(this.router.url, true));
      } else this.problem.set(outcome);
    } finally {
      this.busy.set(false);
    }
  }
}
