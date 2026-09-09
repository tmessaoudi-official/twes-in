// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { CompanyApi, CompanyRefused } from './company-api';
import type { CompanyError, MemberRole, MemberRow } from './company-types';

/** The member list of one company, and the two things an administrator does to it. */
@Injectable({ providedIn: 'root' })
export class MembersFacade {
  private readonly api = inject(CompanyApi);
  private readonly membersSignal = signal<readonly MemberRow[]>([]);
  private readonly busySignal = signal(false);
  private readonly errorSignal = signal<CompanyError | null>(null);

  readonly members = this.membersSignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();

  async load(companyId: string): Promise<void> {
    this.busySignal.set(true);
    try {
      this.membersSignal.set(await this.api.members(companyId));
      this.errorSignal.set(null);
    } catch (error) {
      this.membersSignal.set([]);
      this.errorSignal.set(codeOf(error));
    } finally {
      this.busySignal.set(false);
    }
  }

  /**
   * Returns the row the API answered with, or null when it refused. The row says whether that address
   * became a member or was sent an invitation, which is the only thing the page needs to know afterwards.
   */
  async add(companyId: string, email: string, role: MemberRole): Promise<MemberRow | null> {
    return this.mutate(companyId, () => this.api.addMember(companyId, email, role));
  }

  async remove(companyId: string, userId: string): Promise<boolean> {
    return (await this.mutate(companyId, () => this.api.removeMember(companyId, userId))) !== null;
  }

  private async mutate<T>(companyId: string, call: () => Promise<T>): Promise<T | null> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      const result = await call();
      this.membersSignal.set(await this.api.members(companyId));
      return result;
    } catch (error) {
      this.errorSignal.set(codeOf(error));
      return null;
    } finally {
      this.busySignal.set(false);
    }
  }
}

function codeOf(error: unknown): CompanyError {
  return error instanceof CompanyRefused ? error.code : 'network';
}
