// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject, input, OnInit } from '@angular/core';
import { FormControl, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { Router } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { InvitationFacade } from './invitation-facade';
import { SignedOutLayout } from '../auth/signed-out-layout';

/**
 * The page an invitation link opens. It is reached logged out: the link comes from a mail client and, under
 * SameSite=Strict, arrives with no session cookie at all.
 */
@Component({
  selector: 'app-accept-invitation-page',
  imports: [
    SignedOutLayout,
    ReactiveFormsModule,
    MatFormFieldModule,
    MatInputModule,
    MatButtonModule,
    TranslatePipe,
  ],
  templateUrl: './accept-invitation-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AcceptInvitationPage implements OnInit {
  /** Bound from the route parameter by withComponentInputBinding(). */
  readonly token = input('');

  private readonly invitation = inject(InvitationFacade);
  private readonly router = inject(Router);

  protected readonly offer = this.invitation.offer;
  protected readonly busy = this.invitation.busy;
  protected readonly error = this.invitation.error;
  protected readonly accepted = this.invitation.accepted;

  protected readonly form = new FormGroup({
    displayName: new FormControl('', {
      nonNullable: true,
      validators: [Validators.required, Validators.maxLength(120)],
    }),
    password: new FormControl('', {
      nonNullable: true,
      validators: [Validators.required, Validators.minLength(12)],
    }),
  });

  async ngOnInit(): Promise<void> {
    await this.invitation.load(this.token());
  }

  protected async submit(): Promise<void> {
    if (this.form.invalid || this.busy()) {
      this.form.markAllAsTouched();
      return;
    }
    const { displayName, password } = this.form.getRawValue();
    if (await this.invitation.accept(this.token(), displayName, password)) {
      // The account exists now, but no session does: signing in is a separate, deliberate step.
      await this.router.navigateByUrl('/login');
    }
  }

  /** The address already has an account: nothing to fill in, and signing in stays a separate step. */
  protected async join(): Promise<void> {
    if (this.busy()) {
      return;
    }
    if (await this.invitation.accept(this.token())) {
      await this.router.navigateByUrl('/login');
    }
  }
}
