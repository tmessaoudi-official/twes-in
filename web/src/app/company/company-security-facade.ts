// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { AuthFacade } from '../auth/auth-facade';
import { StepUp } from '../shared/step-up/step-up';
import {
  type CompanySecurity,
  CompanySecurityApi,
  type CompanySecurityError,
  CompanySecurityRefused,
} from './company-security-api';
import { QuietReads } from '../shared/feedback/quiet-reads';

/** What the working company requires of its members' sign-in, and changing it. */
@Injectable({ providedIn: 'root' })
export class CompanySecurityFacade {
  private readonly api = inject(CompanySecurityApi);
  private readonly quietReads = inject(QuietReads);
  private readonly auth = inject(AuthFacade);
  private readonly stepUp = inject(StepUp);
  private readonly securitySignal = signal<CompanySecurity | null>(null);
  private readonly busySignal = signal(false);
  private readonly errorSignal = signal<CompanySecurityError | null>(null);

  readonly security = this.securitySignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();

  async load(companyId: string): Promise<void> {
    // A reload nobody asked for leaves busy as it is, so no action turns disabled under a pointer (QuietReads).
    const marked = !this.quietReads.quiet();
    if (marked) this.busySignal.set(true);
    try {
      this.securitySignal.set(await this.api.read(companyId));
      this.errorSignal.set(null);
    } catch (error) {
      this.errorSignal.set(codeOf(error));
    } finally {
      if (marked) this.busySignal.set(false);
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
      // Turning it off waits for the person to prove who they are again; turning it on asks nothing.
      this.securitySignal.set(
        await this.stepUp.retrying(
          () => this.api.requireSecondFactor(companyId, required),
          (error) => error instanceof CompanySecurityRefused && error.code === 'step_up_required',
          'step_up.intro_mfa_off',
        ),
      );
      // `refresh`, never `load`: the write succeeded, so a moment without the API afterwards must not sign
      // the person out of a session that is still perfectly valid (developer sweep, 2026-09-20).
      await this.auth.refresh();
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
