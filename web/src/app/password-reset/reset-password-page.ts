// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject, input, signal } from '@angular/core';
import { FormControl, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { SignedOutLayout } from '../auth/signed-out-layout';
import { PasswordResetFacade } from './password-reset-facade';
import { PasswordToggle } from '../shared/form/password-toggle';

/**
 * The page a reset link opens, logged out: the new password, twice. Two entries that differ never reach the API, since a
 * typo would lock the person out of their own account. Choosing it starts no session: the page ends by offering sign-in.
 */
@Component({
  selector: 'app-reset-password-page',
  imports: [
    PasswordToggle,
    SignedOutLayout,
    ReactiveFormsModule,
    RouterLink,
    MatFormFieldModule,
    MatInputModule,
    MatButtonModule,
    TranslatePipe,
  ],
  templateUrl: './reset-password-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ResetPasswordPage {
  /** Bound from the route parameter by withComponentInputBinding(). */
  readonly token = input('');

  private readonly reset = inject(PasswordResetFacade);

  protected readonly busy = this.reset.busy;
  protected readonly error = this.reset.error;
  protected readonly done = signal(false);
  protected readonly mismatch = signal(false);
  protected readonly form = new FormGroup({
    password: new FormControl('', {
      nonNullable: true,
      validators: [Validators.required, Validators.minLength(12)],
    }),
    again: new FormControl('', { nonNullable: true, validators: [Validators.required] }),
  });

  protected async submit(): Promise<void> {
    if (this.form.invalid || this.busy()) {
      this.form.markAllAsTouched();
      return;
    }
    const { password, again } = this.form.getRawValue();
    this.mismatch.set(password !== again);
    if (password !== again) return;
    this.done.set(await this.reset.reset(this.token(), password));
  }
}
