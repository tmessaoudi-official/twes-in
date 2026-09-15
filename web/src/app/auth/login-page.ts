// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { FormControl, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { Router } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { HealthFacade } from '../health/health-facade';
import { AuthFacade } from './auth-facade';
import type { LoginError } from './auth-types';

@Component({
  selector: 'app-login-page',
  imports: [
    ReactiveFormsModule,
    MatCardModule,
    MatFormFieldModule,
    MatInputModule,
    MatButtonModule,
    TranslatePipe,
  ],
  templateUrl: './login-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class LoginPage {
  private readonly auth = inject(AuthFacade);
  private readonly router = inject(Router);

  protected readonly form = new FormGroup({
    email: new FormControl('', {
      nonNullable: true,
      validators: [Validators.required, Validators.email],
    }),
    password: new FormControl('', { nonNullable: true, validators: [Validators.required] }),
  });
  /** The second step: a six-digit code or a recovery code, once the password has been accepted. */
  protected readonly codeForm = new FormGroup({
    code: new FormControl('', { nonNullable: true, validators: [Validators.required] }),
  });
  protected readonly step = signal<'password' | 'code'>('password');
  protected readonly submitting = signal(false);
  protected readonly error = signal<LoginError | null>(null);
  protected readonly apiStatus = inject(HealthFacade).status;

  protected async submit(): Promise<void> {
    if (this.form.invalid || this.submitting()) {
      this.form.markAllAsTouched();
      return;
    }
    this.submitting.set(true);
    this.error.set(null);
    const outcome = await this.auth.login(this.form.getRawValue());
    this.submitting.set(false);
    if (outcome.status === 'signed_in') {
      await this.router.navigateByUrl('/');
      return;
    }
    this.form.controls.password.reset();
    if (outcome.status === 'second_factor') {
      this.codeForm.reset();
      this.step.set('code');
      return;
    }
    this.error.set(outcome.error);
  }

  protected async submitCode(): Promise<void> {
    if (this.codeForm.invalid || this.submitting()) {
      this.codeForm.markAllAsTouched();
      return;
    }
    this.submitting.set(true);
    this.error.set(null);
    const outcome = await this.auth.verifySecondFactor(this.codeForm.controls.code.value.trim());
    this.submitting.set(false);
    if (outcome.status === 'signed_in') {
      await this.router.navigateByUrl('/');
      return;
    }
    this.codeForm.reset();
    if (outcome.status === 'refused') {
      this.error.set(outcome.error);
      // An expired or missing half-login cannot be finished with any code: the password is needed again.
      if (outcome.error === 'mfa_not_pending') {
        this.step.set('password');
      }
    }
  }

  protected backToPassword(): void {
    this.codeForm.reset();
    this.error.set(null);
    this.step.set('password');
  }
}
