// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { AuthFacade } from '../auth/auth-facade';
import { ModulesApi, ModulesRefused, type ModulesError } from './modules-api';
import type { ModuleRow } from './modules-types';

/** The modules of the company being worked in, and switching them. */
@Injectable({ providedIn: 'root' })
export class ModulesFacade {
  private readonly api = inject(ModulesApi);
  private readonly auth = inject(AuthFacade);
  private readonly modulesSignal = signal<readonly ModuleRow[]>([]);
  private readonly busySignal = signal(false);
  private readonly errorSignal = signal<ModulesError | null>(null);

  readonly modules = this.modulesSignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();

  async load(companyId: string): Promise<void> {
    this.busySignal.set(true);
    try {
      this.modulesSignal.set(await this.api.list(companyId));
      this.errorSignal.set(null);
    } catch (error) {
      this.errorSignal.set(codeOf(error));
    } finally {
      this.busySignal.set(false);
    }
  }

  /**
   * True when the API accepted it. The modules are read again, and so is the signed-in state, which names the
   * modules the company has on: the navigation and the module pages follow it.
   */
  async switch(companyId: string, key: string, enabled: boolean): Promise<boolean> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      await this.api.switch(companyId, key, enabled);
      this.modulesSignal.set(await this.api.list(companyId));
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

  /**
   * « Me prévenir » on a planned module, or its withdrawal. True when the API accepted it; the module's row then
   * reads what the API kept.
   */
  async setInterest(companyId: string, key: string, interested: boolean): Promise<boolean> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      const row = await this.api.setInterest(companyId, key, interested);
      this.modulesSignal.update((rows) => rows.map((each) => (each.key === row.key ? row : each)));
      return true;
    } catch (error) {
      this.errorSignal.set(codeOf(error));
      return false;
    } finally {
      this.busySignal.set(false);
    }
  }

  clearError(): void {
    this.errorSignal.set(null);
  }
}

function codeOf(error: unknown): ModulesError {
  return error instanceof ModulesRefused ? error.code : 'network';
}
