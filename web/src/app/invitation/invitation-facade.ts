// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { InvitationApi, InvitationRefused } from './invitation-api';
import type { InvitationError, InvitationOffer } from './invitation-types';

/** The state of one invitation link being looked at, and then used. */
@Injectable({ providedIn: 'root' })
export class InvitationFacade {
  private readonly api = inject(InvitationApi);
  private readonly offerSignal = signal<InvitationOffer | null>(null);
  private readonly busySignal = signal(false);
  private readonly errorSignal = signal<InvitationError | null>(null);
  private readonly acceptedSignal = signal(false);

  readonly offer = this.offerSignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();
  readonly accepted = this.acceptedSignal.asReadonly();

  async load(token: string): Promise<InvitationOffer | null> {
    this.busySignal.set(true);
    try {
      const offer = await this.api.offer(token);
      this.offerSignal.set(offer);
      this.errorSignal.set(null);
      return offer;
    } catch (error) {
      this.offerSignal.set(null);
      this.errorSignal.set(codeOf(error));
      return null;
    } finally {
      this.busySignal.set(false);
    }
  }

  async accept(token: string, displayName: string, password: string): Promise<boolean> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      await this.api.accept(token, displayName, password);
      this.acceptedSignal.set(true);
      return true;
    } catch (error) {
      this.errorSignal.set(codeOf(error));
      return false;
    } finally {
      this.busySignal.set(false);
    }
  }
}

function codeOf(error: unknown): InvitationError {
  return error instanceof InvitationRefused ? error.code : 'network';
}
