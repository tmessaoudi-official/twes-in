// SPDX-License-Identifier: AGPL-3.0-or-later

import { requestState } from '../shared/feedback/request-state';
import { inject, Injectable } from '@angular/core';
import { PasswordResetApi, PasswordResetRefused } from './password-reset-api';
import type { PasswordResetError } from './password-reset-types';
import { sessionSignal } from '../shared/session/session-data';

/** What the two forgot-password pages share: whether a call is running, why the last one was refused, and whether a link was asked for. */
@Injectable({ providedIn: 'root' })
export class PasswordResetFacade {
  private readonly api = inject(PasswordResetApi);
  private readonly state = requestState<PasswordResetError>((error) =>
    error instanceof PasswordResetRefused ? error.code : 'refused',
  );
  private readonly requestedSignal = sessionSignal(false);

  readonly busy = this.state.busy;
  readonly error = this.state.error;
  readonly requested = this.requestedSignal.asReadonly();

  /** Asks for a link. The page says the same afterwards whether or not the address has an account. */
  async request(email: string, locale: string): Promise<void> {
    if (await this.run(() => this.api.forgot(email, locale))) this.requestedSignal.set(true);
  }

  /**
   * Drops how the last visit ended, its refusal included: this facade outlives the pages that read it, so a page
   * opened again starts on its form, never on a link asked for or refused before.
   */
  forget(): void {
    this.requestedSignal.set(false);
    this.state.clearError();
  }

  /** Chooses the new password with the link; true when it was accepted. */
  reset(token: string, newPassword: string): Promise<boolean> {
    return this.run(() => this.api.reset(token, newPassword));
  }

  private async run(call: () => Promise<void>): Promise<boolean> {
    return this.state.write(call);
  }
}
