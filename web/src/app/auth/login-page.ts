// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject, input, signal } from '@angular/core';
import { FormControl, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { HealthFacade } from '../shared/health/health-facade';
import { SignupFacade } from '../signup/signup-facade';
import { AuthFacade } from './auth-facade';
import type { DemoCountry, LoginError } from './auth-types';
import { LanguageFacade } from '../shared/i18n/language-facade';
import { PasskeyClient } from './passkey-client';
import { SignedOutLayout } from './signed-out-layout';
import { PasswordToggle } from '../shared/form/password-toggle';
import { returnAddress } from '../shared/session/return-to';
import { onTypedText, typedText } from '../shared/form/typed-text';

@Component({
  selector: 'app-login-page',
  imports: [
    PasswordToggle,
    ReactiveFormsModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatButtonModule,
    RouterLink,
    SignedOutLayout,
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
      validators: [onTypedText(Validators.required), onTypedText(Validators.email)],
    }),
    password: new FormControl('', { nonNullable: true, validators: [Validators.required] }),
  });
  /** The second step: a six-digit code or a recovery code, once the password has been accepted. */
  protected readonly codeForm = new FormGroup({
    code: new FormControl('', { nonNullable: true, validators: [Validators.required] }),
  });
  /** `?expired=1`: the shell sent the person here because their session ended while a page was open. */
  readonly expired = input<string>();
  /** `?returnTo=`: the page to open once signed in, kept only when it is a page of this app. */
  readonly returnTo = input<string>();
  protected readonly step = signal<'password' | 'code'>('password');
  protected readonly submitting = signal(false);
  protected readonly error = signal<LoginError | null>(null);
  protected readonly apiStatus = inject(HealthFacade).status;
  /** Offered only where the browser can answer with a passkey at all. */
  private readonly passkeyClient = inject(PasskeyClient);
  /** A passkey offered only where this browser, at this address, can use one. */
  protected readonly passkeysSupported = computed(() => this.passkeyClient.supported());
  /** The address the installation's passkeys work at, when this page's is not one: said where the choice would be. */
  protected readonly passkeysElsewhere = computed(() => this.passkeyClient.elsewhere());
  private readonly signup = inject(SignupFacade);
  /** Offered only while the platform's operators have opened signup. */
  protected readonly signupOpen = computed(() => this.signup.availability()?.enabled === true);
  private readonly language = inject(LanguageFacade);
  /** The countries « Essayer la démo » offers, none while the platform's operators keep it closed. */
  protected readonly demoCountries = signal<readonly DemoCountry[]>([]);
  protected readonly demoError = signal<LoginError | null>(null);

  constructor() {
    void this.signup.loadAvailability();
    void this.auth.demoAvailability().then((demo) => this.demoCountries.set(demo.countries));
  }

  protected countryName(code: string): string {
    return new Intl.DisplayNames([this.language.current()], { type: 'region' }).of(code) ?? code;
  }

  protected async enterDemo(country: string): Promise<void> {
    if (this.submitting()) return;
    this.submitting.set(true);
    this.demoError.set(null);
    const outcome = await this.auth.enterDemo(country);
    this.submitting.set(false);
    if (outcome.status === 'signed_in') {
      await this.router.navigateByUrl('/');
      return;
    }
    if (outcome.status === 'refused') {
      this.demoError.set(outcome.error);
      if (outcome.error === 'demo_off') this.demoCountries.set([]);
    }
  }

  protected async submit(): Promise<void> {
    if (this.form.invalid || this.submitting()) {
      this.form.markAllAsTouched();
      return;
    }
    this.submitting.set(true);
    this.error.set(null);
    const typed = this.form.getRawValue();
    const outcome = await this.auth.login({ ...typed, email: typedText(typed.email) });
    this.submitting.set(false);
    if (outcome.status === 'signed_in') {
      await this.router.navigateByUrl(returnAddress(this.returnTo()) ?? '/');
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
      await this.router.navigateByUrl(returnAddress(this.returnTo()) ?? '/');
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

  /** The same second step, answered with a passkey instead of a code. */
  protected async usePasskey(): Promise<void> {
    if (this.submitting()) {
      return;
    }
    this.submitting.set(true);
    this.error.set(null);
    const outcome = await this.auth.signInWithPasskey();
    this.submitting.set(false);
    if (outcome.status === 'signed_in') {
      await this.router.navigateByUrl(returnAddress(this.returnTo()) ?? '/');
      return;
    }
    if (outcome.status === 'refused') {
      this.error.set(outcome.error);
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
