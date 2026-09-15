// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { SignupApi, SignupRefused } from './signup-api';
import type {
  SignupAvailability,
  SignupCompleted,
  SignupDetails,
  SignupError,
} from './signup-types';

const CLOSED: SignupAvailability = { enabled: false, countries: [] };

/** Signup as signals: whether it is open, the link being looked at, and how asking and finishing went. */
@Injectable({ providedIn: 'root' })
export class SignupFacade {
  private readonly api = inject(SignupApi);
  private readonly availabilitySignal = signal<SignupAvailability | null>(null);
  private readonly linkEmailSignal = signal<string | null>(null);
  private readonly requestedSignal = signal(false);
  private readonly completedSignal = signal<SignupCompleted | null>(null);
  private readonly busySignal = signal(false);
  private readonly errorSignal = signal<SignupError | null>(null);

  /** Null until read; closed when the API cannot say, since offering a signup that may not exist helps nobody. */
  readonly availability = this.availabilitySignal.asReadonly();
  readonly linkEmail = this.linkEmailSignal.asReadonly();
  readonly requested = this.requestedSignal.asReadonly();
  readonly completed = this.completedSignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();

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
    this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      return await call();
    } catch (error) {
      this.errorSignal.set(error instanceof SignupRefused ? error.code : 'network');
      return refused;
    } finally {
      this.busySignal.set(false);
    }
  }
}
