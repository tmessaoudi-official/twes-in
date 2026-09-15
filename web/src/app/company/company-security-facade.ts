// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { AuthFacade } from '../auth/auth-facade';
import {
  type CompanySecurity,
  CompanySecurityApi,
  type CompanySecurityError,
  CompanySecurityRefused,
} from './company-security-api';

/** What the working company requires of its members' sign-in, and changing it. */
@Injectable({ providedIn: 'root' })
export class CompanySecurityFacade {
  private readonly api = inject(CompanySecurityApi);
  private readonly auth = inject(AuthFacade);
  private readonly securitySignal = signal<CompanySecurity | null>(null);
  private readonly busySignal = signal(false);
  private readonly errorSignal = signal<CompanySecurityError | null>(null);

  readonly security = this.securitySignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();

  async load(companyId: string): Promise<void> {
    this.busySignal.set(true);
    try {
      this.securitySignal.set(await this.api.read(companyId));
      this.errorSignal.set(null);
    } catch (error) {
      this.errorSignal.set(codeOf(error));
    } finally {
      this.busySignal.set(false);
    }
  }

  /**
   * True when the API accepted it. The signed-in state is read again, because it says whether this very account must
   * now enrol a second factor before it can do anything else.
   */
  async requireSecondFactor(companyId: string, required: boolean): Promise<boolean> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      this.securitySignal.set(await this.api.requireSecondFactor(companyId, required));
      await this.auth.load();
      return true;
    } catch (error) {
      this.errorSignal.set(codeOf(error));
      return false;
    } finally {
      this.busySignal.set(false);
    }
  }
}

function codeOf(error: unknown): CompanySecurityError {
  return error instanceof CompanySecurityRefused ? error.code : 'network';
}
