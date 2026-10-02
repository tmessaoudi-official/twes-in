// SPDX-License-Identifier: AGPL-3.0-or-later

import { DOCUMENT, inject, Injectable, InjectionToken, signal } from '@angular/core';
import {
  PageMemoryStorage,
  SettingsFacade,
  type SettingsStorage,
} from '../settings/settings-facade';
import { PRESENTATION } from '../settings/settings-registry';
import { StepUp } from '../step-up/step-up';

/** What customer view may hide on screen. */
export type SensitiveField = 'cost' | 'supplier-codes' | 'other-customers';

/** Where this tab remembers that customer view is on: its session storage, so another tab is not affected. */
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
 * Customer view (docs/SPEC.md § 7, 2026-09-23 slice 5): one click hides, on this tab's screens, what the company chose
 * to keep from a customer looking at them — a product's cost, the codes its suppliers print. It hides on screen only:
 * what the API sends at all is product.cost.read's, and a screen never erases what it hid. The price-check screen turns
 * it on by itself.
 */
@Injectable({ providedIn: 'root' })
export class CustomerView {
  private readonly storage = inject(CUSTOMER_VIEW_STORAGE);
  private readonly settings = inject(SettingsFacade);
  private readonly stepUp = inject(StepUp);
  private readonly chosen = {
    cost: this.settings.value(PRESENTATION.customerViewCost),
    'supplier-codes': this.settings.value(PRESENTATION.customerViewSupplierCodes),
    'other-customers': this.settings.value(PRESENTATION.customerViewOtherCustomers),
  } as const;
  private readonly on$ = signal(this.remembered());

  readonly active = this.on$.asReadonly();

  on(): void {
    this.set(true);
  }

  /**
   * Switches it off with no question. For a screen that turned it on itself and puts it back as it found it; a
   * button a person can press is `leave()`, since a customer looking at the screen would otherwise only have to
   * press it.
   */
  off(): void {
    this.set(false);
  }

  /** Leaving takes the password or a passkey; true once it is off, false when the person gave up. */
  async leave(): Promise<boolean> {
    if (!this.on$()) return true;
    if (!(await this.stepUp.request())) return false;
    this.set(false);
    return true;
  }

  toggle(): void {
    if (this.on$()) void this.leave();
    else this.set(true);
  }

  /** Whether a screen leaves this field out now; read in a template or a `computed`, it follows both answers. */
  hides(field: SensitiveField): boolean {
    return this.on$() && this.chosen[field]();
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
