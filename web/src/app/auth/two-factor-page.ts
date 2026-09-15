// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { FormControl, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { Router } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from './auth-facade';
import type { LoginError, TotpEnrolment } from './auth-types';
import { QrCode } from './qr-code';

/**
 * Setting up an authenticator: scan, confirm with one code, write the recovery codes down.
 *
 * Outside the shell on purpose: an account a company requires to enrol is refused by every other endpoint until
 * it has, so the shell could not even load. Opening the page starts an enrolment, which changes nothing about the
 * account until a code confirms it.
 */
@Component({
  selector: 'app-two-factor-page',
  imports: [
    ReactiveFormsModule,
    MatCardModule,
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

  protected readonly alreadyEnrolled = this.auth.me()?.mfa.enrolled === true;
  protected readonly required = this.auth.me()?.mfa.required === true;
  protected readonly enrolment = signal<TotpEnrolment | null>(null);
  protected readonly recoveryCodes = signal<string[] | null>(null);
  protected readonly error = signal<LoginError | null>(null);
  protected readonly submitting = signal(false);
  protected readonly form = new FormGroup({
    code: new FormControl('', { nonNullable: true, validators: [Validators.required] }),
  });
  /** For an account already enrolled: a current authenticator code buys a new set of recovery codes. */
  protected readonly regenerateForm = new FormGroup({
    code: new FormControl('', { nonNullable: true, validators: [Validators.required] }),
  });
  /** The secret in groups of four, which is how people copy a key by hand. */
  protected readonly groupedSecret = computed(
    () =>
      this.enrolment()
        ?.secret.match(/.{1,4}/g)
        ?.join(' ') ?? '',
  );

  constructor() {
    if (!this.alreadyEnrolled) {
      void this.begin();
    }
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
}
