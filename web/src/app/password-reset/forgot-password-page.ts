// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { FormControl, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { SignedOutLayout } from '../auth/signed-out-layout';
import { LanguageFacade } from '../shared/i18n/language-facade';
import { PasswordResetFacade } from './password-reset-facade';

/**
 * The first step of getting back into an account: an address, and a mailed link. What the page says afterwards is the
 * same whether or not the address has an account, because the API answers the same, and saying otherwise would tell
 * anybody who types an address whether it is registered.
 */
@Component({
  selector: 'app-forgot-password-page',
  imports: [
    SignedOutLayout,
    ReactiveFormsModule,
    RouterLink,
    MatFormFieldModule,
    MatInputModule,
    MatButtonModule,
    TranslatePipe,
  ],
  templateUrl: './forgot-password-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ForgotPasswordPage {
  private readonly reset = inject(PasswordResetFacade);
  private readonly language = inject(LanguageFacade);

  protected readonly requested = this.reset.requested;
  protected readonly busy = this.reset.busy;
  protected readonly error = this.reset.error;
  protected readonly form = new FormGroup({
    email: new FormControl('', {
      nonNullable: true,
      validators: [Validators.required, Validators.email],
    }),
  });

  protected async submit(): Promise<void> {
    if (this.form.invalid || this.busy()) {
      this.form.markAllAsTouched();
      return;
    }
    // The mail is written in the language the person is reading this page in.
    await this.reset.request(this.form.controls.email.value.trim(), this.language.current());
  }
}
