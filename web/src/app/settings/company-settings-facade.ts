// SPDX-License-Identifier: AGPL-3.0-or-later

import { requestState } from '../shared/feedback/request-state';
import { inject, Injectable, signal } from '@angular/core';
import { SettingsApi, SettingsRefused } from '../shared/settings/settings-api';
import { SettingsFacade } from '../shared/settings/settings-facade';
import type {
  SettingRow,
  SettingsError,
  VersionedSettingChange,
} from '../shared/settings/settings-types';
import { COMPANY_CHAINS } from './settings-forms';

/** A company's defaults, as its settings page reads and changes them: always at the company level. */
@Injectable({ providedIn: 'root' })
export class CompanySettings {
  private readonly api = inject(SettingsApi);
  private readonly live = inject(SettingsFacade);
  private readonly rowsSignal = signal<readonly SettingRow[]>([]);
  private readonly state = requestState(codeOf);

  readonly rows = this.rowsSignal.asReadonly();
  readonly busy = this.state.busy;
  readonly error = this.state.error;

  async load(companyId: string): Promise<void> {
    await this.state.read(() => this.fetch(companyId));
  }

  /** True when the API accepted every change, together; the settings are read again after. */
  async save(companyId: string, changes: readonly VersionedSettingChange[]): Promise<boolean> {
    return this.write(companyId, () => this.api.changeAll(companyId, 'company', changes));
  }

  /** Forgets the company's value: the setting falls back to the platform's, else the declared default. */
  async reset(companyId: string, key: string): Promise<boolean> {
    return this.write(companyId, () => this.api.reset(companyId, key, 'company'));
  }

  clearError(): void {
    this.state.clearError();
  }

  private async fetch(companyId: string): Promise<void> {
    const chains = await Promise.all(
      COMPANY_CHAINS.map((chain) => this.api.chain(companyId, chain)),
    );
    this.rowsSignal.set(chains.flat());
  }

  private async write(companyId: string, call: () => Promise<unknown>): Promise<boolean> {
    return this.state.write(async () => {
      await call();
      await this.fetch(companyId);
      // What was just written is a value the whole application reads: the accent, the density, the language, the
      // company's defaults. A live change never comes back to the tab that caused it, so this tab reads the chain
      // again itself — otherwise the colour changed only after a refresh (developer, 2026-09-20).
      this.live.refresh();
    });
  }
}

function codeOf(error: unknown): SettingsError {
  return error instanceof SettingsRefused ? error.code : 'network';
}
