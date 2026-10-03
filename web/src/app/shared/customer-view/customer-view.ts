// SPDX-License-Identifier: AGPL-3.0-or-later

import { DOCUMENT, inject, Injectable, InjectionToken, signal } from '@angular/core';
import { PageMemoryStorage, type SettingsStorage } from '../settings/settings-facade';
import { StepUp } from '../step-up/step-up';

/** Where this tab remembers that the customer screen is open: its session storage, so another tab is not affected. */
export const CUSTOMER_VIEW_STORAGE = new InjectionToken<SettingsStorage>('CUSTOMER_VIEW_STORAGE', {
  providedIn: 'root',
  factory: () => {
    try {
      // Reading the property itself throws when site data is blocked.
      return inject(DOCUMENT).defaultView?.sessionStorage ?? new PageMemoryStorage();
    } catch {
      return new PageMemoryStorage();
    }
  },
});

const KEY = 'twes.customer-view';

/**
 * Whether this tab is locked on the customer screen (docs/SPEC.md § 7, 2026-10-03 08:20): the screen a customer may
 * look at, which shows an allow-list the API itself sends and reaches nothing else. While it is on, every other screen
 * of the signed-in app sends the tab back to it, and leaving takes the password or a passkey, since a customer looking
 * at it would otherwise only have to press a button. Per tab, so another window of the counter is not locked with it.
 */
@Injectable({ providedIn: 'root' })
export class CustomerView {
  private readonly storage = inject(CUSTOMER_VIEW_STORAGE);
  private readonly stepUp = inject(StepUp);
  private readonly on$ = signal(this.remembered());

  readonly active = this.on$.asReadonly();

  on(): void {
    this.set(true);
  }

  /** Leaving takes the password or a passkey; true once it is off, false when the person gave up. */
  async leave(): Promise<boolean> {
    if (!this.on$()) return true;
    if (!(await this.stepUp.request())) return false;
    this.set(false);
    return true;
  }

  private set(on: boolean): void {
    this.on$.set(on);
    try {
      if (on) this.storage.setItem(KEY, '1');
      else this.storage.removeItem(KEY);
    } catch {
      // A browser refusing storage keeps the choice for this page only; the signal above already holds it.
    }
  }

  private remembered(): boolean {
    try {
      return this.storage.getItem(KEY) === '1';
    } catch {
      return false;
    }
  }
}
