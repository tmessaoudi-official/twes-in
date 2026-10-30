// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  afterNextRender,
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  type ElementRef,
  inject,
  Injector,
  input,
  signal,
  viewChild,
} from '@angular/core';
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
import type { DemoCountry, LoginError, LoginOutcome } from './auth-types';
import { countryName } from '../shared/i18n/country-names';
import { LanguageFacade } from '../shared/i18n/language-facade';
import { PasskeyClient } from './passkey-client';
import { SignedOutLayout } from './signed-out-layout';
import { PasswordToggle } from '../shared/form/password-toggle';
import { returnAddress } from '../shared/session/return-to';
import { onTypedText, typedText } from '../shared/form/typed-text';
import { typedCode } from '../shared/form/typed-code';

/** What the browser answers on its own, before the API hears anything. */
const BROWSER_REFUSALS: readonly LoginError[] = [
  'passkey_cancelled',
  'passkey_already_registered',
  'passkey_not_here',
];

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
  /**
   * The address first, then the password for every address, known or not, so the page never says which are; beside
   * the address, a passkey alone where the installation allows it. Then the code a second factor owes.
   */
  protected readonly step = signal<'email' | 'password' | 'code'>('email');
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
  /** Offered once the API gave options for it: never while the installation keeps it off. */
  protected readonly passkeyAlone = signal(false);
  /** The browser's offer under the address field, withdrawn once the person moves on or leaves. */
  private offer: AbortController | null = null;
  private readonly injector = inject(Injector);
  private readonly emailInput = viewChild<ElementRef<HTMLInputElement>>('loginEmail');
  private readonly passwordInput = viewChild<ElementRef<HTMLInputElement>>('loginPassword');

  constructor() {
    void this.signup.loadAvailability();
    void this.auth.demoAvailability().then((demo) => this.demoCountries.set(demo.countries));
    void this.offerPasskeys();
    inject(DestroyRef).onDestroy(() => this.withdrawOffer());
  }

  /** « Continuer »: the password, whatever the address; the API is asked nothing until it is sent. */
  protected continue(): void {
    const email = this.form.controls.email;
    if (email.invalid) {
      email.markAsTouched();
      return;
    }
    this.withdrawOffer();
    this.error.set(null);
    this.step.set('password');
    this.focus(this.passwordInput);
  }

  protected changeAddress(): void {
    this.form.controls.password.reset();
    this.error.set(null);
    this.step.set('email');
    this.focus(this.emailInput);
    void this.offerPasskeys();
  }

  /** The browser's own dialog, at once; the offer under the field gives way to it. */
  protected async usePasskeyAlone(): Promise<void> {
    if (this.submitting()) return;
    this.withdrawOffer();
    this.submitting.set(true);
    this.error.set(null);
    const options = await this.auth.passkeyAloneOptions();
    const outcome = options === null ? null : await this.auth.signInWithPasskeyAlone(options);
    this.submitting.set(false);
    if (options === null) {
      // Switched off since the page opened: nothing to offer any more.
      this.passkeyAlone.set(false);
      return;
    }
    await this.afterPasskeyAlone(outcome, false);
  }

  /**
   * Asks the API for options once: a passkey is offered only with them. Where the browser can, it also offers its
   * passkeys under the address field until the person picks one, moves on to the password, or leaves.
   */
  private async offerPasskeys(): Promise<void> {
    if (!this.passkeysSupported()) return;
    const options = await this.auth.passkeyAloneOptions();
    this.passkeyAlone.set(options !== null);
    if (
      options === null ||
      this.step() !== 'email' ||
      !(await this.passkeyClient.offersUnderTheField())
    ) {
      return;
    }
    this.withdrawOffer();
    const offer = new AbortController();
    this.offer = offer;
    const outcome = await this.auth.signInWithPasskeyAlone(options, {
      mediation: 'conditional',
      signal: offer.signal,
    });
    if (this.offer === offer) this.offer = null;
    await this.afterPasskeyAlone(outcome, true);
  }

  private async afterPasskeyAlone(outcome: LoginOutcome | null, offered: boolean): Promise<void> {
    if (outcome === null) return;
    if (outcome.status === 'signed_in') {
      await this.router.navigateByUrl(returnAddress(this.returnTo()) ?? '/');
      return;
    }
    if (outcome.status !== 'refused') return;
    // The browser withdrawing its own offer under the field is nothing the person did, and offering again at once
    // would only be withdrawn again.
    if (offered && BROWSER_REFUSALS.includes(outcome.error)) return;
    this.error.set(outcome.error);
    // A request answered is spent: the field offers the passkeys again on new options, after the browser's own dialog
    // whatever came of it, after a pick from the field only when the API refused that passkey.
    if (this.step() === 'email' && (!offered || outcome.error === 'invalid_passkey')) {
      void this.offerPasskeys();
    }
  }

  private withdrawOffer(): void {
    this.offer?.abort();
    this.offer = null;
  }

  private focus(field: () => ElementRef<HTMLInputElement> | undefined): void {
    afterNextRender(() => field()?.nativeElement.focus(), { injector: this.injector });
  }

  /** A country as every screen names it: its flag and the browser's name in the screen's language. */
  protected countryName(code: string): string {
    return countryName(code, this.language.current());
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
    const outcome = await this.auth.verifySecondFactor(
      typedCode(this.codeForm.controls.code.value),
    );
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
