// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, inject, Injectable, signal } from '@angular/core';
import { AuthFacade } from '../auth/auth-facade';
import { CompanyApi, CompanyRefused } from './company-api';
import type { CompanyError, CompanyOption } from './company-types';

/**
 * What the switcher shows and does. Switching changes the session on the server, so the signed-in state is
 * re-read from the API afterwards rather than patched here: the session is the truth, not this signal.
 */
@Injectable({ providedIn: 'root' })
export class CompanyFacade {
  private readonly api = inject(CompanyApi);
  private readonly auth = inject(AuthFacade);
  private readonly companiesSignal = signal<readonly CompanyOption[]>([]);
  private readonly switchingSignal = signal(false);
  private readonly errorSignal = signal<CompanyError | null>(null);

  readonly companies = this.companiesSignal.asReadonly();
  readonly switching = this.switchingSignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();

  /** The company the session is working in, as the auth state last reported it. */
  readonly current = computed(() => this.auth.me()?.company ?? null);

  /** True only when there is somewhere else to go; one company needs no switcher. */
  readonly canSwitch = computed(() => this.companiesSignal().length > 1);

  async load(): Promise<readonly CompanyOption[]> {
    try {
      const companies = await this.api.companies();
      this.companiesSignal.set(companies);
      this.errorSignal.set(null);
      return companies;
    } catch (error) {
      this.companiesSignal.set([]);
      this.errorSignal.set(error instanceof CompanyRefused ? error.code : 'network');
      return [];
    }
  }

  /** Returns whether the session actually moved. */
  async switchTo(companyId: string): Promise<boolean> {
    if (companyId === this.current()?.id) {
      return true;
    }
    this.switchingSignal.set(true);
    this.errorSignal.set(null);
    try {
      await this.api.switchTo(companyId);
      await this.auth.load();
      return true;
    } catch (error) {
      this.errorSignal.set(error instanceof CompanyRefused ? error.code : 'network');
      return false;
    } finally {
      this.switchingSignal.set(false);
    }
  }
}
