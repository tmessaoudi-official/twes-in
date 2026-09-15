// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject, input, OnInit } from '@angular/core';
import { FormControl, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { LanguageFacade } from '../shared/i18n/language-facade';
import { SignupFacade } from './signup-facade';

/**
 * The page a signup link opens, logged out: the account, and the company it will own. Finishing starts no session,
 * so the page ends by saying whether the company waits for an operator's approval and offering the sign-in page.
 */
@Component({
  selector: 'app-finish-signup-page',
  imports: [
    ReactiveFormsModule,
    RouterLink,
    MatCardModule,
    MatFormFieldModule,
    MatInputModule,
    MatSelectModule,
    MatButtonModule,
    TranslatePipe,
  ],
  templateUrl: './finish-signup-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class FinishSignupPage implements OnInit {
  /** Bound from the route parameter by withComponentInputBinding(). */
  readonly token = input('');

  private readonly signup = inject(SignupFacade);
  private readonly language = inject(LanguageFacade);

  protected readonly linkEmail = this.signup.linkEmail;
  protected readonly completed = this.signup.completed;
  protected readonly busy = this.signup.busy;
  protected readonly error = this.signup.error;
  protected readonly countries = computed(() => this.signup.availability()?.countries ?? []);
  protected readonly form = new FormGroup({
    displayName: new FormControl('', {
      nonNullable: true,
      validators: [Validators.required, Validators.maxLength(120)],
    }),
    password: new FormControl('', {
      nonNullable: true,
      validators: [Validators.required, Validators.minLength(12)],
    }),
    companyName: new FormControl('', {
      nonNullable: true,
      validators: [Validators.required, Validators.maxLength(160)],
    }),
    countryCode: new FormControl('', { nonNullable: true, validators: [Validators.required] }),
    // The company's zone starts as the one this browser is in, which is right for nearly everyone who signs up.
    timezone: new FormControl(Intl.DateTimeFormat().resolvedOptions().timeZone, {
      nonNullable: true,
      validators: [Validators.required],
    }),
  });

  async ngOnInit(): Promise<void> {
    await Promise.all([this.signup.loadAvailability(), this.signup.loadLink(this.token())]);
  }

  protected countryName(code: string): string {
    return new Intl.DisplayNames([this.language.current()], { type: 'region' }).of(code) ?? code;
  }

  protected async submit(): Promise<void> {
    if (this.form.invalid || this.busy()) {
      this.form.markAllAsTouched();
      return;
    }
    const value = this.form.getRawValue();
    await this.signup.complete(this.token(), {
      displayName: value.displayName.trim(),
      password: value.password,
      companyName: value.companyName.trim(),
      countryCode: value.countryCode,
      timezone: value.timezone.trim(),
    });
  }
}
