// SPDX-License-Identifier: AGPL-3.0-or-later

import { requestState } from '../shared/feedback/request-state';
import { inject, Injectable, signal } from '@angular/core';
import { AuthFacade } from '../auth/auth-facade';
import { StepUp } from '../shared/step-up/step-up';
import { SubscriptionApi, SubscriptionRefused } from './subscription-api';
import type {
  DeclaredPayment,
  SubscriptionError,
  SubscriptionView,
  WaitingPayment,
} from './subscription-types';

/**
 * The working company's subscription, and the payments it declares. A declaration changes what the company may do —
 * it holds an unpaid company open — so the signed-in state is read again afterwards and the whole application
 * follows, not only this page.
 */
@Injectable({ providedIn: 'root' })
export class SubscriptionFacade {
  private readonly api = inject(SubscriptionApi);
  private readonly auth = inject(AuthFacade);
  private readonly stepUp = inject(StepUp);
  private readonly subscriptionSignal = signal<SubscriptionView | null>(null);
  private readonly waitingSignal = signal<readonly WaitingPayment[]>([]);
  private readonly state = requestState(codeOf);
  /** Told apart from "not loaded yet": a company licensing does not manage has no subscription at all. */
  private readonly managedSignal = signal(true);

  readonly subscription = this.subscriptionSignal.asReadonly();
  readonly waiting = this.waitingSignal.asReadonly();
  readonly busy = this.state.busy;
  readonly error = this.state.error;
  readonly managed = this.managedSignal.asReadonly();

  async load(companyId: string): Promise<void> {
    await this.state.read(async () => {
      const view = await this.api.ofCompany(companyId);
      this.subscriptionSignal.set(view);
      this.managedSignal.set(view !== null);
    });
  }

  /** True when the API kept the declaration; the page then shows what it kept. */
  async declare(companyId: string, payment: DeclaredPayment): Promise<boolean> {
    return this.state.write(async () => {
      await this.api.declare(companyId, payment);
      await this.reread(companyId);
    });
  }

  /** An operator's queue: every payment waiting for a decision, across all companies. */
  async loadWaiting(): Promise<void> {
    await this.state.read(async () => this.waitingSignal.set(await this.api.waiting()));
  }

  async confirm(declarationId: string, periods: number, note: string | null): Promise<boolean> {
    return this.decide(() => this.api.confirm(declarationId, periods, note));
  }

  async reject(declarationId: string, note: string | null): Promise<boolean> {
    return this.decide(() => this.api.reject(declarationId, note));
  }

  private async decide(call: () => Promise<unknown>): Promise<boolean> {
    return this.state.write(async () => {
      // A decision is a platform write: it waits for the operator to prove who is at the screen (docs/SPEC.md § 7).
      await this.stepUp.retrying(
        call,
        (error) => error instanceof SubscriptionRefused && error.code === 'step_up_required',
        'step_up.intro_change',
      );
      this.waitingSignal.set(await this.api.waiting());
      // An operator can decide about the company they are themselves working in, and its status, access and
      // subscription are all things the shell reads from the session (developer sweep, 2026-09-20).
      await this.auth.refresh();
    });
  }

  /** What the company may do has just changed, so the session is read again with the subscription. */
  private async reread(companyId: string): Promise<void> {
    this.subscriptionSignal.set(await this.api.ofCompany(companyId));
    // `refresh`, never `load`: the write succeeded, so a moment without the API afterwards must not sign
    // the person out of a session that is still perfectly valid (developer sweep, 2026-09-20).
    await this.auth.refresh();
  }
}

function codeOf(error: unknown): SubscriptionError {
  return error instanceof SubscriptionRefused ? error.code : 'network';
}
