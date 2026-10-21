// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { requestState } from '../shared/feedback/request-state';
import { SettingsApi, SettingsRefused } from '../shared/settings/settings-api';
import type {
  SettingLevel,
  SettingRow,
  SettingsError,
  PartySubject,
  VersionedSettingChange,
} from '../shared/settings/settings-types';

/** The level a customer's or a customer group's own values are stored at. */
export const levelOf = (subject: PartySubject): SettingLevel =>
  'customerId' in subject ? 'customer' : 'customer_group';

/** The parties chain as one customer or one customer group sees it, read and changed at its own level. */
@Injectable({ providedIn: 'root' })
export class PartySettings {
  private readonly api = inject(SettingsApi);
  private readonly rowsSignal = signal<readonly SettingRow[]>([]);
  private readonly state = requestState(codeOf);

  readonly rows = this.rowsSignal.asReadonly();
  readonly busy = this.state.busy;
  readonly error = this.state.error;

  async load(companyId: string, subject: PartySubject): Promise<void> {
    await this.state.read(async () =>
      this.rowsSignal.set(await this.api.chain(companyId, 'parties', subject)),
    );
  }

  /** True when the API accepted every change, together; the chain is read again after. */
  async save(
    companyId: string,
    subject: PartySubject,
    changes: readonly VersionedSettingChange[],
  ): Promise<boolean> {
    return this.write(companyId, subject, () =>
      this.api.changeAll(companyId, levelOf(subject), changes, subject),
    );
  }

  /** Forgets the subject's own value: the setting falls back to the group's or the company's. */
  async reset(companyId: string, subject: PartySubject, key: string): Promise<boolean> {
    return this.write(companyId, subject, () =>
      this.api.reset(companyId, key, levelOf(subject), undefined, subject),
    );
  }

  clearError(): void {
    this.state.clearError();
  }

  private async write(
    companyId: string,
    subject: PartySubject,
    call: () => Promise<unknown>,
  ): Promise<boolean> {
    return this.state.write(async () => {
      await call();
      this.rowsSignal.set(await this.api.chain(companyId, 'parties', subject));
    });
  }
}

function codeOf(error: unknown): SettingsError {
  return error instanceof SettingsRefused ? error.code : 'network';
}
