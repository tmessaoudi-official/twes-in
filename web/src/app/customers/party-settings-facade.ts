// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
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
  private readonly busySignal = signal(false);
  private readonly errorSignal = signal<SettingsError | null>(null);

  readonly rows = this.rowsSignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();

  async load(companyId: string, subject: PartySubject): Promise<void> {
    this.busySignal.set(true);
    try {
      this.rowsSignal.set(await this.api.chain(companyId, 'parties', subject));
      this.errorSignal.set(null);
    } catch (error) {
      this.errorSignal.set(codeOf(error));
    } finally {
      this.busySignal.set(false);
    }
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
    this.errorSignal.set(null);
  }

  private async write(
    companyId: string,
    subject: PartySubject,
    call: () => Promise<unknown>,
  ): Promise<boolean> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      await call();
      this.rowsSignal.set(await this.api.chain(companyId, 'parties', subject));
      return true;
    } catch (error) {
      this.errorSignal.set(codeOf(error));
      return false;
    } finally {
      this.busySignal.set(false);
    }
  }
}

function codeOf(error: unknown): SettingsError {
  return error instanceof SettingsRefused ? error.code : 'network';
}
