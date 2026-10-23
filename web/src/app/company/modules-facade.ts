// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable } from '@angular/core';
import { AuthFacade } from '../auth/auth-facade';
import { requestState } from '../shared/feedback/request-state';
import { ModulesApi, ModulesRefused, type ModulesError } from './modules-api';
import type { ModuleRow } from './modules-types';
import { sessionSignal } from '../shared/session/session-data';

/** The modules of the company being worked in, and switching them. */
@Injectable({ providedIn: 'root' })
export class ModulesFacade {
  private readonly api = inject(ModulesApi);
  private readonly auth = inject(AuthFacade);
  private readonly modulesSignal = sessionSignal<readonly ModuleRow[]>([]);
  private readonly state = requestState(codeOf);

  readonly modules = this.modulesSignal.asReadonly();
  readonly busy = this.state.busy;
  readonly error = this.state.error;

  async load(companyId: string): Promise<void> {
    await this.state.read(async () => this.modulesSignal.set(await this.api.list(companyId)));
  }

  /**
   * True when the API accepted it. The modules are read again, and so is the signed-in state, which names the
   * modules the company has on: the navigation and the module pages follow it.
   */
  async switch(companyId: string, key: string, enabled: boolean): Promise<boolean> {
    return this.state.write(async () => {
      await this.api.switch(companyId, key, enabled);
      this.modulesSignal.set(await this.api.list(companyId));
      // `refresh`, never `load`: the write succeeded, so a moment without the API afterwards must not sign
      // the person out of a session that is still perfectly valid (developer sweep, 2026-09-20).
      await this.auth.refresh();
    });
  }

  /**
   * « Me prévenir » on a planned module, or its withdrawal. True when the API accepted it; the module's row then
   * reads what the API kept.
   */
  async setInterest(companyId: string, key: string, interested: boolean): Promise<boolean> {
    return this.state.write(async () => {
      const row = await this.api.setInterest(companyId, key, interested);
      this.modulesSignal.update((rows) => rows.map((each) => (each.key === row.key ? row : each)));
    });
  }

  clearError(): void {
    this.state.clearError();
  }
}

function codeOf(error: unknown): ModulesError {
  return error instanceof ModulesRefused ? error.code : 'network';
}
