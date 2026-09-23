// SPDX-License-Identifier: AGPL-3.0-or-later

import { DOCUMENT, inject, Injectable, InjectionToken, signal } from '@angular/core';
import {
  PageMemoryStorage,
  SettingsFacade,
  type SettingsStorage,
} from '../settings/settings-facade';
import { PRESENTATION } from '../settings/settings-registry';

/** What customer view may hide on screen. */
export type SensitiveField = 'cost' | 'supplier-codes';

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
  private readonly chosen = {
    cost: this.settings.value(PRESENTATION.customerViewCost),
    'supplier-codes': this.settings.value(PRESENTATION.customerViewSupplierCodes),
  } as const;
  private readonly on$ = signal(this.remembered());

  readonly active = this.on$.asReadonly();

  on(): void {
    this.set(true);
  }

  off(): void {
    this.set(false);
  }

  toggle(): void {
    this.set(!this.on$());
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
