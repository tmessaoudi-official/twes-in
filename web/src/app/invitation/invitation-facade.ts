// SPDX-License-Identifier: AGPL-3.0-or-later

import { requestState } from '../shared/feedback/request-state';
import { inject, Injectable, signal } from '@angular/core';
import { AuthFacade } from '../auth/auth-facade';
import { InvitationApi, InvitationRefused } from './invitation-api';
import type { InvitationError, InvitationOffer } from './invitation-types';

/** The state of one invitation link being looked at, and then used. */
@Injectable({ providedIn: 'root' })
export class InvitationFacade {
  private readonly api = inject(InvitationApi);
  private readonly auth = inject(AuthFacade);
  private readonly offerSignal = signal<InvitationOffer | null>(null);
  private readonly state = requestState(codeOf);
  private readonly acceptedSignal = signal(false);

  readonly offer = this.offerSignal.asReadonly();
  readonly busy = this.state.busy;
  readonly error = this.state.error;
  readonly accepted = this.acceptedSignal.asReadonly();

  async load(token: string): Promise<InvitationOffer | null> {
    await this.state.read(async () => this.offerSignal.set(await this.api.offer(token)), {
      failed: () => this.offerSignal.set(null),
    });
    return this.offerSignal();
  }

  /** An address that has an account accepts with neither a name nor a password: the link proves the address. */
  async accept(
    token: string,
    displayName: string | null = null,
    password: string | null = null,
  ): Promise<boolean> {
    return this.state.write(async () => {
      await this.api.accept(token, displayName, password);
      // Accepting creates a membership: without this the new company is missing from the switcher, and a person
      // already signed in lands on a shell that does not know they joined (developer sweep, 2026-09-20).
      await this.auth.refresh();
      this.acceptedSignal.set(true);
    });
  }
}

function codeOf(error: unknown): InvitationError {
  return error instanceof InvitationRefused ? error.code : 'network';
}
