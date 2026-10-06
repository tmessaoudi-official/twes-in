// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, inject, Injectable } from '@angular/core';
import { Session } from '../session/session';
import { StepUp } from '../step-up/step-up';
import { CustomerScreenHold } from './customer-screen-hold';

/**
 * Whether the sign-in is held on the customer screen (docs/SPEC.md § 7, 2026-10-06 19:44): the screen a customer may
 * look at, which shows an allow-list the API itself sends. The API keeps the hold in the session and answers nothing
 * else while it lasts, so every tab of the browser, a new one included, is held with it; this only follows what the
 * API said. Leaving takes the password or a passkey, which the API spends, since a customer looking at the screen
 * would otherwise only have to press a button.
 */
@Injectable({ providedIn: 'root' })
export class CustomerView {
  private readonly session = inject(Session);
  private readonly hold = inject(CustomerScreenHold);
  private readonly stepUp = inject(StepUp);

  readonly active = computed(() => (this.session.me()?.customerScreenCompanyId ?? null) !== null);

  /** Holds the sign-in on the working company's screen; false when there is none or the API would not. */
  async on(): Promise<boolean> {
    if (this.active()) return true;
    const company = this.session.me()?.company?.id;
    return company !== undefined && (await this.hold.hold(company));
  }

  /** Leaving takes the password or a passkey; true once it is off, false when the person gave up or the API kept it. */
  async leave(): Promise<boolean> {
    if (!this.active()) return true;
    if (!(await this.stepUp.request())) return false;
    return this.hold.release();
  }
}
