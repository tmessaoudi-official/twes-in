// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { PasswordResetApi, PasswordResetRefused } from './password-reset-api';
import type { PasswordResetError } from './password-reset-types';

/** What the two forgot-password pages share: whether a call is running, why the last one was refused, and whether a link was asked for. */
@Injectable({ providedIn: 'root' })
export class PasswordResetFacade {
  private readonly api = inject(PasswordResetApi);
  private readonly busySignal = signal(false);
  private readonly errorSignal = signal<PasswordResetError | null>(null);
  private readonly requestedSignal = signal(false);

  readonly busy = this.busySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();
  readonly requested = this.requestedSignal.asReadonly();

  /** Asks for a link. The page says the same afterwards whether or not the address has an account. */
  async request(email: string, locale: string): Promise<void> {
    if (await this.run(() => this.api.forgot(email, locale))) this.requestedSignal.set(true);
  }

  /** Chooses the new password with the link; true when it was accepted. */
  reset(token: string, newPassword: string): Promise<boolean> {
    return this.run(() => this.api.reset(token, newPassword));
  }

  private async run(call: () => Promise<void>): Promise<boolean> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      await call();
      return true;
    } catch (error) {
      this.errorSignal.set(error instanceof PasswordResetRefused ? error.code : 'refused');
      return false;
    } finally {
      this.busySignal.set(false);
    }
  }
}
