// SPDX-License-Identifier: AGPL-3.0-or-later

import { requestState } from '../shared/feedback/request-state';
import { inject, Injectable } from '@angular/core';
import { SignupApi, SignupRefused } from './signup-api';
import type {
  SignupAvailability,
  SignupCompleted,
  SignupDetails,
  SignupError,
} from './signup-types';
import { sessionSignal } from '../shared/session/session-data';

const CLOSED: SignupAvailability = { enabled: false, countries: [] };

/** Signup as signals: whether it is open, the link being looked at, and how asking and finishing went. */
@Injectable({ providedIn: 'root' })
export class SignupFacade {
  private readonly api = inject(SignupApi);
  private readonly availabilitySignal = sessionSignal<SignupAvailability | null>(null);
  private readonly linkEmailSignal = sessionSignal<string | null>(null);
  private readonly requestedSignal = sessionSignal(false);
  private readonly completedSignal = sessionSignal<SignupCompleted | null>(null);
  private readonly state = requestState<SignupError>((error) =>
    error instanceof SignupRefused ? error.code : 'network',
  );

  /** Null until read; closed when the API cannot say, since offering a signup that may not exist helps nobody. */
  readonly availability = this.availabilitySignal.asReadonly();
  readonly linkEmail = this.linkEmailSignal.asReadonly();
  readonly requested = this.requestedSignal.asReadonly();
  readonly completed = this.completedSignal.asReadonly();
  readonly busy = this.state.busy;
  readonly error = this.state.error;

  /**
   * Drops how the last visit ended, its refusal included: this facade outlives the pages that read it, so a page
   * opened again starts on its form, never on a link asked for, a signup finished or a refusal from before.
   */
  forget(): void {
    this.requestedSignal.set(false);
    this.completedSignal.set(null);
    this.linkEmailSignal.set(null);
    this.state.clearError();
  }

  async loadAvailability(): Promise<SignupAvailability> {
    let availability = CLOSED;
    try {
      availability = await this.api.availability();
    } catch {
      // Closed, as above.
    }
    this.availabilitySignal.set(availability);
    return availability;
  }

  async request(email: string, locale: string): Promise<boolean> {
    return this.run(async () => {
      await this.api.request(email, locale);
      this.requestedSignal.set(true);
      return true;
    }, false);
  }

  async loadLink(token: string): Promise<string | null> {
    this.linkEmailSignal.set(null);
    return this.run(async () => {
      const email = await this.api.link(token);
      this.linkEmailSignal.set(email);
      return email;
    }, null);
  }

  async complete(token: string, details: SignupDetails): Promise<SignupCompleted | null> {
    return this.run(async () => {
      const completed = await this.api.complete(token, details);
      this.completedSignal.set(completed);
      return completed;
    }, null);
  }

  private async run<T>(call: () => Promise<T>, refused: T): Promise<T> {
    const answer = await this.state.writeAnswer(call);
    return answer === null ? refused : answer;
  }
}
