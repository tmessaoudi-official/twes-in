// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { CompanyApi, CompanyRefused } from './company-api';
import type { CompanyError, CompanyProfile, CompanyProfileChanges } from './company-types';

/** The profile of the company being worked in, and its revision. */
@Injectable({ providedIn: 'root' })
export class CompanyProfileFacade {
  private readonly api = inject(CompanyApi);
  private readonly profileSignal = signal<CompanyProfile | null>(null);
  private readonly busySignal = signal(false);
  private readonly errorSignal = signal<CompanyError | null>(null);

  readonly profile = this.profileSignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();

  async load(companyId: string): Promise<void> {
    this.busySignal.set(true);
    try {
      this.profileSignal.set(await this.api.profile(companyId));
      this.errorSignal.set(null);
    } catch (error) {
      this.errorSignal.set(codeOf(error));
    } finally {
      this.busySignal.set(false);
    }
  }

  /** True when the API kept the revision; the page then shows what it kept, normalised. */
  async save(companyId: string, changes: CompanyProfileChanges): Promise<boolean> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      this.profileSignal.set(await this.api.reviseProfile(companyId, changes));
      return true;
    } catch (error) {
      this.errorSignal.set(codeOf(error));
      return false;
    } finally {
      this.busySignal.set(false);
    }
  }
}

function codeOf(error: unknown): CompanyError {
  return error instanceof CompanyRefused ? error.code : 'network';
}
