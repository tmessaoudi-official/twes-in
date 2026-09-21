// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, inject, Injectable, signal } from '@angular/core';
import { InventoryApi } from '../inventory/inventory-api';
import type { StockLocationRow } from '../inventory/inventory-types';
import { ProductsApi, ProductsRefused } from './products-api';
import type { ProductHomeRow, ProductsError } from './products-types';

/**
 * Where one product normally lives, per establishment (docs/SPEC.md row 101), with the locations it may be given.
 *
 * Its own facade rather than a corner of `ProductsFacade`, as the defaults tab has its own: a tab that fails to load
 * or is saving says so about itself, instead of putting the whole product screen in an error nobody asked about.
 *
 * It reads the warehouse through the inventory ADAPTER, not that feature's facade: what is needed is the list of
 * locations, not the state of the stock screens, and sharing their signals would have this tab reload whenever a
 * movement was recorded elsewhere.
 */
@Injectable()
export class ProductHomes {
  private readonly api = inject(ProductsApi);
  private readonly stock = inject(InventoryApi);
  private readonly homesSignal = signal<readonly ProductHomeRow[]>([]);
  private readonly locationsSignal = signal<readonly StockLocationRow[]>([]);
  private readonly busySignal = signal(false);
  private readonly errorSignal = signal<ProductsError | null>(null);

  readonly homes = this.homesSignal.asReadonly();
  readonly locations = this.locationsSignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();

  /** The establishments this product is already at home in, so a second home there is offered as a move, not a duplicate. */
  readonly establishmentsAtHome = computed(
    () => new Set(this.homesSignal().map((home) => home.establishmentId)),
  );

  async load(companyId: string, productId: string): Promise<void> {
    await this.run(async () => {
      const [homes, locations] = await Promise.all([
        this.api.homes(companyId, productId),
        this.stock.locations(companyId),
      ]);
      this.homesSignal.set(homes);
      this.locationsSignal.set(locations);
    });
  }

  /** Gives the product a home there; one it already had in that establishment moves rather than doubling. */
  async set(companyId: string, productId: string, locationId: string): Promise<boolean> {
    return this.run(async () => {
      await this.api.setHome(companyId, productId, locationId);
      this.homesSignal.set(await this.api.homes(companyId, productId));
    });
  }

  async clear(companyId: string, productId: string, establishmentId: string): Promise<boolean> {
    return this.run(async () => {
      await this.api.clearHome(companyId, productId, establishmentId);
      this.homesSignal.set(await this.api.homes(companyId, productId));
    });
  }

  private async run(work: () => Promise<void>): Promise<boolean> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      await work();
      return true;
    } catch (error) {
      this.errorSignal.set(error instanceof ProductsRefused ? error.code : 'network');
      return false;
    } finally {
      this.busySignal.set(false);
    }
  }
}
