// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable } from '@angular/core';
import { requestState } from '../shared/feedback/request-state';
import { AuthFacade } from '../auth/auth-facade';
import { StepUp } from '../shared/step-up/step-up';
import {
  type CompanySecurity,
  CompanySecurityApi,
  type CompanySecurityError,
  CompanySecurityRefused,
} from './company-security-api';
import { sessionSignal } from '../shared/session/session-data';

/** What the working company requires of its members' sign-in, and changing it. */
@Injectable({ providedIn: 'root' })
export class CompanySecurityFacade {
  private readonly api = inject(CompanySecurityApi);
  private readonly auth = inject(AuthFacade);
  private readonly stepUp = inject(StepUp);
  private readonly securitySignal = sessionSignal<CompanySecurity | null>(null);
  private readonly state = requestState(codeOf);

  readonly security = this.securitySignal.asReadonly();
  readonly busy = this.state.busy;
  readonly error = this.state.error;

  async load(companyId: string): Promise<void> {
    await this.state.read(async () => this.securitySignal.set(await this.api.read(companyId)));
  }

  /**
   * True when the API accepted it. The signed-in state is read again, because it says whether this very account must
   * now enrol a second factor before it can do anything else.
   */
  async requireSecondFactor(companyId: string, required: boolean): Promise<boolean> {
    return this.state.write(async () => {
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
    });
  }
}

function codeOf(error: unknown): CompanySecurityError {
  return error instanceof CompanySecurityRefused ? error.code : 'network';
}
