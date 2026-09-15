// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { PlatformApi, PlatformRefused } from './platform-api';
import type {
  AccountAction,
  PlatformAccountRow,
  PlatformCompanyRow,
  PlatformError,
  PlatformSignup,
  SignupSwitch,
} from './platform-types';

/** What an operator runs the platform with: who may sign up, the companies waiting for a decision, and accounts. */
@Injectable({ providedIn: 'root' })
export class PlatformFacade {
  private readonly api = inject(PlatformApi);
  private readonly waitingSignal = signal<readonly PlatformCompanyRow[]>([]);
  private readonly signupSignal = signal<PlatformSignup | null>(null);
  private readonly busySignal = signal(false);
  private readonly errorSignal = signal<PlatformError | null>(null);

  private readonly accountsSignal = signal<readonly PlatformAccountRow[]>([]);

  readonly waiting = this.waitingSignal.asReadonly();
  readonly accounts = this.accountsSignal.asReadonly();
  /** Null until read. */
  readonly signup = this.signupSignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();

  async load(): Promise<void> {
    this.busySignal.set(true);
    try {
      const [waiting, signup, accounts] = await Promise.all([
        this.api.waitingCompanies(),
        this.api.signup(),
        this.api.accounts(''),
      ]);
      this.waitingSignal.set(waiting);
      this.signupSignal.set(signup);
      this.accountsSignal.set(accounts);
      this.errorSignal.set(null);
    } catch (error) {
      this.errorSignal.set(codeOf(error));
    } finally {
      this.busySignal.set(false);
    }
  }

  /** The accounts whose address or name holds that text; the page opens on the first of them all. */
  async findAccounts(text: string): Promise<void> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      this.accountsSignal.set(await this.api.accounts(text));
    } catch (error) {
      this.errorSignal.set(codeOf(error));
    } finally {
      this.busySignal.set(false);
    }
  }

  /** The account is shown as the platform answers it, so a refused action leaves it as it was. */
  async actOnAccount(userId: string, action: AccountAction): Promise<boolean> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      const updated = await this.api.actOnAccount(userId, action);
      this.accountsSignal.update((rows) =>
        rows.map((row) => (row.id === updated.id ? updated : row)),
      );
      return true;
    } catch (error) {
      this.errorSignal.set(codeOf(error));
      return false;
    } finally {
      this.busySignal.set(false);
    }
  }

  approve(companyId: string): Promise<boolean> {
    return this.decide(() => this.api.approve(companyId));
  }

  reject(companyId: string): Promise<boolean> {
    return this.decide(() => this.api.reject(companyId));
  }

  /** The switch shows the new value only once the platform holds it. */
  async setSignup(key: SignupSwitch, value: boolean): Promise<void> {
    this.errorSignal.set(null);
    try {
      await this.api.setSignup(key, value);
      const current = this.signupSignal();
      if (current !== null) {
        this.signupSignal.set(
          key === 'signup.enabled'
            ? { ...current, enabled: value }
            : { ...current, approvalRequired: value },
        );
      }
    } catch (error) {
      this.errorSignal.set(codeOf(error));
    }
  }

  /** A decided company leaves the waiting list, so the list is read again rather than edited in place. */
  private async decide(call: () => Promise<unknown>): Promise<boolean> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      await call();
      this.waitingSignal.set(await this.api.waitingCompanies());
      return true;
    } catch (error) {
      this.errorSignal.set(codeOf(error));
      return false;
    } finally {
      this.busySignal.set(false);
    }
  }
}

function codeOf(error: unknown): PlatformError {
  return error instanceof PlatformRefused ? error.code : 'network';
}
