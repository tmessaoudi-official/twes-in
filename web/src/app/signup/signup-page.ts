// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject, OnInit } from '@angular/core';
import { FormControl, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { LanguageFacade } from '../shared/i18n/language-facade';
import { SignupFacade } from './signup-facade';

/**
 * The first step of signup: an address, and a mailed link. What the page says afterwards is the same whether or not the
 * address already has an account, because the API answers the same, and saying otherwise would tell anybody who types
 * an address whether it is registered.
 */
@Component({
  selector: 'app-signup-page',
  imports: [
    ReactiveFormsModule,
    RouterLink,
    MatCardModule,
    MatFormFieldModule,
    MatInputModule,
    MatButtonModule,
    TranslatePipe,
  ],
  templateUrl: './signup-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SignupPage implements OnInit {
  private readonly signup = inject(SignupFacade);
  private readonly language = inject(LanguageFacade);

  protected readonly availability = this.signup.availability;
  protected readonly requested = this.signup.requested;
  protected readonly busy = this.signup.busy;
  protected readonly error = this.signup.error;
  protected readonly form = new FormGroup({
    email: new FormControl('', {
      nonNullable: true,
      validators: [Validators.required, Validators.email],
    }),
  });

  async ngOnInit(): Promise<void> {
    await this.signup.loadAvailability();
  }

  protected async submit(): Promise<void> {
    if (this.form.invalid || this.busy()) {
      this.form.markAllAsTouched();
      return;
    }
    // The mail is written in the language the person is reading this page in.
    await this.signup.request(this.form.controls.email.value.trim(), this.language.current());
  }
}
