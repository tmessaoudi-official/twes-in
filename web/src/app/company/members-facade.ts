// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable } from '@angular/core';
import { AuthFacade } from '../auth/auth-facade';
import { requestState } from '../shared/feedback/request-state';
import { StepUp } from '../shared/step-up/step-up';
import { CompanyApi, CompanyRefused } from './company-api';
import type { CompanyError, MemberRole, MemberRow } from './company-types';
import { sessionSignal } from '../shared/session/session-data';

/** The member list of one company, and what an administrator does to it: invite, withdraw an invitation, remove. */
@Injectable({ providedIn: 'root' })
export class MembersFacade {
  private readonly api = inject(CompanyApi);
  private readonly auth = inject(AuthFacade);
  private readonly stepUp = inject(StepUp);
  private readonly membersSignal = sessionSignal<readonly MemberRow[]>([]);
  private readonly state = requestState(codeOf);

  readonly members = this.membersSignal.asReadonly();
  readonly busy = this.state.busy;
  readonly error = this.state.error;

  async load(companyId: string): Promise<void> {
    await this.state.read(async () => this.membersSignal.set(await this.api.members(companyId)), {
      failed: () => this.membersSignal.set([]),
    });
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

  async withdraw(companyId: string, invitationId: string): Promise<boolean> {
    return (
      (await this.mutate(companyId, () => this.api.withdrawInvitation(companyId, invitationId))) !==
      null
    );
  }

  private async mutate<T>(companyId: string, call: () => Promise<T>): Promise<T | null> {
    return this.state.writeAnswer(async () => {
      // Adding or removing somebody waits for the person to prove who they are again.
      const result = await this.stepUp.retrying(
        call,
        (error) => error instanceof CompanyRefused && error.code === 'step_up_required',
        'step_up.intro_sensitive',
      );
      this.membersSignal.set(await this.api.members(companyId));
      // What was just written can be this person's OWN rights: the session is read again so the menu, the
      // permission-gated controls and the record bars follow at once (developer, 2026-09-20). `refresh`, never
      // `load`: a moment without the API after a successful write is not a sign-out.
      await this.auth.refresh();
      return result;
    });
  }
}

function codeOf(error: unknown): CompanyError {
  return error instanceof CompanyRefused ? error.code : 'network';
}
