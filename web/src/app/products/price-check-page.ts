// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  signal,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { TranslatePipe } from '@ngx-translate/core';
import { CustomerDisplay } from '../shared/customer-display/customer-display';
import { CUSTOMER_VIEW_STORAGE, CustomerView } from '../shared/customer-view/customer-view';
import { FormatFacade } from '../shared/i18n/format-facade';
import { type Scan, ScanBus, type ScanOutcome } from '../shared/scan/scan-bus';
import { Session } from '../shared/session/session';
import type { SettingsStorage } from '../shared/settings/settings-facade';
import { PageTabs } from '../shared/ui/page-tabs';
import { PRODUCTS_TABS } from './products-nav';
import { ProductsApi } from './products-api';
import type { ProductScan } from './products-types';

/** Where this tab notes that the price check, and not the counter, turned customer view on. */
const MARKER = 'twes.price-check-view';

/**
 * The price check (docs/SPEC.md § 7, 2026-09-23 slice 6): a screen a customer may look at, which answers a scan with
 * what the product is and what it costs them, taxes included, as the company's documents would count it. It turns
 * customer view on while it is open, and leaves it as it found it: a counter that was already on stays on. What it
 * turned on is written down in the tab's session storage, so a reload, which would find it on and take it for the
 * counter's own, still puts it back when the screen is left. A checked item goes to the customer display.
 */
@Component({
  selector: 'app-price-check-page',
  imports: [
    FormsModule,
    MatButtonModule,
    MatCardModule,
    MatFormFieldModule,
    MatInputModule,
    PageTabs,
    TranslatePipe,
  ],
  templateUrl: './price-check-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PriceCheckPage {
  private readonly api = inject(ProductsApi);
  private readonly session = inject(Session);
  private readonly bus = inject(ScanBus);
  private readonly display = inject(CustomerDisplay);
  protected readonly format = inject(FormatFacade);
  protected readonly tabs = PRODUCTS_TABS;

  /** The product the last scan named, or null before any scan and after an unknown one. */
  protected readonly found = signal<ProductScan | null>(null);
  /** The last code no product answers to. */
  protected readonly unknown = signal<string | null>(null);
  protected readonly code = signal('');
  private readonly currency = computed(() => this.session.me()?.company?.currency ?? '');

  constructor() {
    const view = inject(CustomerView);
    const storage = inject(CUSTOMER_VIEW_STORAGE);
    const turnedOnHere = this.marked(storage) || !view.active();
    if (turnedOnHere) this.mark(storage, true);
    view.on();
    inject(DestroyRef).onDestroy(() => {
      this.display.clear();
      if (turnedOnHere) {
        view.off();
        this.mark(storage, false);
      }
    });
    this.bus.handle((scan) => this.check(scan));
  }

  private marked(storage: SettingsStorage): boolean {
    try {
      return storage.getItem(MARKER) === '1';
    } catch {
      return false;
    }
  }

  private mark(storage: SettingsStorage, on: boolean): void {
    try {
      if (on) storage.setItem(MARKER, '1');
      else storage.removeItem(MARKER);
    } catch {
      // A browser refusing storage loses the reload case only; leaving the screen still puts the view back.
    }
  }

  protected price(value: string): string {
    return `${this.format.amount(value, null)} ${this.currency()}`.trim();
  }

  protected submit(event: Event): void {
    event.preventDefault();
    const code = this.code().trim();
    if (code === '') return;
    this.code.set('');
    void this.bus.receive(code, 'wedge');
  }

  private async check(scan: Scan): Promise<ScanOutcome> {
    const companyId = this.session.me()?.company?.id;
    if (companyId === undefined) return { kind: 'unclaimed' };
    const found = await this.api.scan(companyId, scan.code);
    if (found === null) {
      this.found.set(null);
      this.unknown.set(scan.code);
      return { kind: 'refused', key: 'price_check.unknown', params: { code: scan.code } };
    }
    this.unknown.set(null);
    this.found.set(found);
    this.display.show({
      name: found.name,
      quantity: '1',
      unitPrice: found.unitPriceGross,
      unitPriceNet: found.unitPriceNet,
    });
    return {
      kind: 'done',
      key: 'price_check.shown',
      params: { name: found.name },
      product: { name: found.name, unitPrice: found.unitPriceGross },
    };
  }
}
