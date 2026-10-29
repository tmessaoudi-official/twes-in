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
import { SettingsApi, SettingsRefused } from '../shared/settings/settings-api';
import type { SettingRow } from '../shared/settings/settings-types';

/** The setting saying whether a member may enter the company with a passkey instead of the password. */
export const PASSKEY_ENTRY = 'identity.passkey_entry';

/** Whether the company lets its members enter with a passkey, the version its own value holds, and who may change it. */
export interface PasskeyEntry {
  readonly on: boolean;
  readonly version: number;
  readonly writable: boolean;
}

/** What the working company requires of its members' sign-in, and changing it. */
@Injectable({ providedIn: 'root' })
export class CompanySecurityFacade {
  private readonly api = inject(CompanySecurityApi);
  private readonly auth = inject(AuthFacade);
  private readonly stepUp = inject(StepUp);
  private readonly settings = inject(SettingsApi);
  private readonly passkeyEntrySignal = sessionSignal<PasskeyEntry | null>(null);
  private readonly securitySignal = sessionSignal<CompanySecurity | null>(null);
  private readonly state = requestState(codeOf);

  readonly security = this.securitySignal.asReadonly();
  readonly passkeyEntry = this.passkeyEntrySignal.asReadonly();
  readonly busy = this.state.busy;
  readonly error = this.state.error;

  async load(companyId: string): Promise<void> {
    await this.state.read(async () => {
      const [security, rows] = await Promise.all([
        this.api.read(companyId),
        this.settings.chain(companyId, 'presentation'),
      ]);
      this.securitySignal.set(security);
      this.passkeyEntrySignal.set(passkeyEntryOf(rows));
    });
  }

  /** True when the API accepted it; what the company holds is read again after, its version with it. */
  async takePasskeys(companyId: string, on: boolean): Promise<boolean> {
    const current = this.passkeyEntrySignal();
    if (current === null) return false;
    return this.state.write(async () => {
      await this.settings.changeAll(companyId, 'company', [
        { key: PASSKEY_ENTRY, value: on, version: current.version },
      ]);
      this.passkeyEntrySignal.set(
        passkeyEntryOf(await this.settings.chain(companyId, 'presentation')),
      );
    });
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
  if (error instanceof SettingsRefused) return error.code;
  return error instanceof CompanySecurityRefused ? error.code : 'network';
}

function passkeyEntryOf(rows: readonly SettingRow[]): PasskeyEntry | null {
  const row = rows.find((candidate) => candidate.key === PASSKEY_ENTRY);
  return row === undefined
    ? null
    : {
        on: row.value === true,
        version: row.versions.company ?? 0,
        writable: row.writableLevels.includes('company'),
      };
}
