// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { SettingsApi, SettingsRefused } from '../shared/settings/settings-api';
import type { SettingRow, SettingsError } from '../shared/settings/settings-types';
import { COMPANY_CHAINS, type SettingChange } from './settings-forms';

/** A company's defaults, as its settings page reads and changes them: always at the company level. */
@Injectable({ providedIn: 'root' })
export class CompanySettings {
  private readonly api = inject(SettingsApi);
  private readonly rowsSignal = signal<readonly SettingRow[]>([]);
  private readonly busySignal = signal(false);
  private readonly errorSignal = signal<SettingsError | null>(null);

  readonly rows = this.rowsSignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();

  async load(companyId: string): Promise<void> {
    this.busySignal.set(true);
    try {
      await this.fetch(companyId);
      this.errorSignal.set(null);
    } catch (error) {
      this.errorSignal.set(codeOf(error));
    } finally {
      this.busySignal.set(false);
    }
  }

  /** True when the API accepted every change; the settings are read again after. */
  async save(companyId: string, changes: readonly SettingChange[]): Promise<boolean> {
    return this.write(companyId, async () => {
      for (const change of changes) {
        await this.api.change(companyId, change.key, 'company', change.value);
      }
    });
  }

  /** Forgets the company's value: the setting falls back to the platform's, else the declared default. */
  async reset(companyId: string, key: string): Promise<boolean> {
    return this.write(companyId, () => this.api.reset(companyId, key, 'company'));
  }

  clearError(): void {
    this.errorSignal.set(null);
  }

  private async fetch(companyId: string): Promise<void> {
    const chains = await Promise.all(
      COMPANY_CHAINS.map((chain) => this.api.chain(companyId, chain)),
    );
    this.rowsSignal.set(chains.flat());
  }

  private async write(companyId: string, call: () => Promise<unknown>): Promise<boolean> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      await call();
      await this.fetch(companyId);
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
