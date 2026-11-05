// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject, OnInit } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormControl, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { WebParameters } from '../shared/build/web-parameters';
import { DurationPipe } from '../shared/i18n/duration-pipe';
import { LanguageFacade } from '../shared/i18n/language-facade';
import { SignupFacade } from './signup-facade';
import { emailRule, emailTypo } from '../shared/form/email-rule';
import { SignedOutLayout } from '../auth/signed-out-layout';
import { onTypedText, typedText } from '../shared/form/typed-text';
import { SpentBudget } from '../shared/errors/spent-budget';

/**
 * The first step of signup: an address, and a mailed link. What the page says afterwards is the same whether or not the
 * address already has an account, because the API answers the same, and saying otherwise would tell anybody who types
 * an address whether it is registered.
 */
@Component({
  selector: 'app-signup-page',
  imports: [
    SignedOutLayout,
    ReactiveFormsModule,
    RouterLink,
    MatFormFieldModule,
    MatInputModule,
    MatButtonModule,
    TranslatePipe,
    DurationPipe,
  ],
  templateUrl: './signup-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SignupPage implements OnInit {
  /** The operator's durations this screen states, never a fixed hour. */
  /** When a spent budget may be tried again, for a refusal's `retry`. */
  protected readonly budget = inject(SpentBudget);
  protected readonly parameters = inject(WebParameters);
  private readonly signup = inject(SignupFacade);
  private readonly language = inject(LanguageFacade);

  protected readonly availability = this.signup.availability;
  protected readonly requested = this.signup.requested;
  protected readonly busy = this.signup.busy;
  protected readonly error = this.signup.error;
  protected readonly form = new FormGroup({
    email: new FormControl('', {
      nonNullable: true,
      validators: [onTypedText(Validators.required), emailRule],
    }),
  });
  private readonly typedEmail = toSignal(this.form.controls.email.valueChanges, {
    initialValue: this.form.controls.email.value,
  });
  /** The address a slip of a key most likely meant, offered under the field and never written into it. */
  protected readonly typo = computed(() => emailTypo(this.typedEmail()));

  async ngOnInit(): Promise<void> {
    this.signup.forget();
    await this.signup.loadAvailability();
  }

  protected async submit(): Promise<void> {
    if (this.form.invalid || this.busy()) {
      this.form.markAllAsTouched();
      return;
    }
    // The mail is written in the language the person is reading this page in.
    await this.signup.request(typedText(this.form.controls.email.value), this.language.current());
  }
}
