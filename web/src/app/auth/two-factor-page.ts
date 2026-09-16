// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { FormControl, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { Router } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from './auth-facade';
import type { LoginError, PasskeySummary, TotpEnrolment } from './auth-types';
import { PasskeyClient } from './passkey-client';
import { QrCode } from './qr-code';
import { SignedOutLayout } from './signed-out-layout';

/**
 * Two-step verification for the signed-in account: an authenticator app, passkeys, and the recovery codes behind both.
 *
 * Outside the shell on purpose: an account a company requires to enrol is refused by every other endpoint until
 * it has, so the shell could not even load. An account with no factor at all starts an authenticator enrolment on
 * arrival, which changes nothing until a code confirms it, and may add a passkey instead.
 */
@Component({
  selector: 'app-two-factor-page',
  imports: [
    SignedOutLayout,
    ReactiveFormsModule,
    MatFormFieldModule,
    MatInputModule,
    MatButtonModule,
    TranslatePipe,
    QrCode,
  ],
  templateUrl: './two-factor-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class TwoFactorPage {
  private readonly auth = inject(AuthFacade);
  private readonly router = inject(Router);

  protected readonly required = this.auth.me()?.mfa.required === true;
  /** Any factor in force; read live, so a passkey added on this page counts at once. */
  protected readonly enrolled = computed(() => this.auth.me()?.mfa.enrolled === true);
  /** Replacing the recovery codes takes an authenticator code, so only an account with one is offered it. */
  protected readonly totp = computed(() => this.auth.me()?.mfa.totp === true);
  protected readonly passkeysSupported = inject(PasskeyClient).supported();
  protected readonly enrolment = signal<TotpEnrolment | null>(null);
  protected readonly recoveryCodes = signal<string[] | null>(null);
  protected readonly error = signal<LoginError | null>(null);
  protected readonly passkeys = signal<PasskeySummary[]>([]);
  protected readonly passkeyError = signal<LoginError | null>(null);
  protected readonly submitting = signal(false);
  protected readonly form = new FormGroup({
    code: new FormControl('', { nonNullable: true, validators: [Validators.required] }),
  });
  /** For an account with an authenticator: a current code buys a new set of recovery codes. */
  protected readonly regenerateForm = new FormGroup({
    code: new FormControl('', { nonNullable: true, validators: [Validators.required] }),
  });
  protected readonly passkeyForm = new FormGroup({
    name: new FormControl('', {
      nonNullable: true,
      validators: [Validators.required, Validators.maxLength(80)],
    }),
  });
  /** The secret in groups of four, which is how people copy a key by hand. */
  protected readonly groupedSecret = computed(
    () =>
      this.enrolment()
        ?.secret.match(/.{1,4}/g)
        ?.join(' ') ?? '',
  );

  constructor() {
    if (!this.enrolled()) {
      void this.begin();
    }
    void this.loadPasskeys();
  }

  protected async confirm(): Promise<void> {
    if (this.form.invalid || this.submitting()) {
      this.form.markAllAsTouched();
      return;
    }
    this.submitting.set(true);
    this.error.set(null);
    const outcome = await this.auth.confirmTotpEnrolment(this.form.controls.code.value.trim());
    this.submitting.set(false);
    this.form.reset();
    if (outcome.ok) {
      this.recoveryCodes.set(outcome.recoveryCodes);
    } else {
      this.error.set(outcome.error);
    }
  }

  protected async regenerate(): Promise<void> {
    if (this.regenerateForm.invalid || this.submitting()) {
      this.regenerateForm.markAllAsTouched();
      return;
    }
    this.submitting.set(true);
    this.error.set(null);
    const outcome = await this.auth.regenerateRecoveryCodes(
      this.regenerateForm.controls.code.value.trim(),
    );
    this.submitting.set(false);
    this.regenerateForm.reset();
    if (outcome.ok) {
      this.recoveryCodes.set(outcome.recoveryCodes);
    } else {
      this.error.set(outcome.error);
    }
  }

  /** For an account with a passkey: using it buys a new set of recovery codes, no authenticator code needed. */
  protected async regenerateWithPasskey(): Promise<void> {
    if (this.submitting()) {
      return;
    }
    this.submitting.set(true);
    this.passkeyError.set(null);
    const outcome = await this.auth.regenerateRecoveryCodesWithPasskey();
    this.submitting.set(false);
    if (outcome.ok) {
      this.recoveryCodes.set(outcome.recoveryCodes);
    } else {
      this.passkeyError.set(outcome.error);
    }
  }

  /** For an account whose factors are passkeys only: an authenticator app is offered, never started unasked. */
  protected async startAuthenticator(): Promise<void> {
    await this.begin();
  }

  protected async addPasskey(): Promise<void> {
    if (this.passkeyForm.invalid || this.submitting()) {
      this.passkeyForm.markAllAsTouched();
      return;
    }
    this.submitting.set(true);
    this.passkeyError.set(null);
    const outcome = await this.auth.addPasskey(this.passkeyForm.controls.name.value.trim());
    this.submitting.set(false);
    if (!outcome.ok) {
      // The name stays, so trying again after a cancelled prompt is one click.
      this.passkeyError.set(outcome.error);
      return;
    }
    this.passkeys.update((list) => [...list, outcome.passkey]);
    this.passkeyForm.reset();
    if (outcome.recoveryCodes.length > 0) {
      this.recoveryCodes.set(outcome.recoveryCodes);
    }
  }

  protected async removePasskey(passkey: PasskeySummary): Promise<void> {
    if (this.submitting()) {
      return;
    }
    this.submitting.set(true);
    this.passkeyError.set(null);
    const outcome = await this.auth.removePasskey(passkey.id);
    this.submitting.set(false);
    if (outcome.ok) {
      this.passkeys.update((list) => list.filter((item) => item.id !== passkey.id));
      // That was the last factor: the page is back where an account with none starts.
      if (!this.enrolled()) {
        await this.begin();
      }
    } else {
      this.passkeyError.set(outcome.error);
    }
  }

  protected async continue(): Promise<void> {
    await this.router.navigateByUrl('/');
  }

  protected async signOut(): Promise<void> {
    await this.auth.logout();
    await this.router.navigateByUrl('/login');
  }

  private async begin(): Promise<void> {
    const outcome = await this.auth.beginTotpEnrolment();
    if (outcome.ok) {
      this.enrolment.set(outcome.enrolment);
    } else {
      this.error.set(outcome.error);
    }
  }

  private async loadPasskeys(): Promise<void> {
    const outcome = await this.auth.listPasskeys();
    if (outcome.ok) {
      this.passkeys.set(outcome.passkeys);
    }
  }
}
