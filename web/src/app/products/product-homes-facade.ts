// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, inject, Injectable, signal } from '@angular/core';
import { InventoryApi } from '../inventory/inventory-api';
import type { StockLocationRow } from '../inventory/inventory-types';
import { ProductsApi, ProductsRefused } from './products-api';
import { appended, groupByEstablishment, moved, without } from './product-home-order';
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

  /** Where it lives, establishment by establishment, each in its order: the first of each is the main home. */
  readonly groups = computed(() => groupByEstablishment(this.homesSignal()));

  /** A home added after the others of its establishment, which a location knows; its first one is the main one. */
  async add(companyId: string, productId: string, locationId: string): Promise<boolean> {
    const place = this.locationsSignal().find((location) => location.id === locationId);
    if (place === undefined) {
      this.errorSignal.set('invalid');
      return false;
    }
    return this.write(companyId, productId, place.establishmentId, (ids) =>
      appended(ids, locationId),
    );
  }

  /** A home one step towards the main end (−1) or away from it (1) in its own establishment. */
  async move(
    companyId: string,
    productId: string,
    home: ProductHomeRow,
    step: -1 | 1,
  ): Promise<boolean> {
    return this.write(companyId, productId, home.establishmentId, (ids) =>
      moved(ids, home.locationId, step),
    );
  }

  /** A place stops being a home; the last one of an establishment clears it, the API having no empty list. */
  async remove(companyId: string, productId: string, home: ProductHomeRow): Promise<boolean> {
    return this.write(companyId, productId, home.establishmentId, (ids) =>
      without(ids, home.locationId),
    );
  }

  /** The establishment's homes as a new list, written whole and read again: the API keeps the order. */
  private async write(
    companyId: string,
    productId: string,
    establishmentId: string,
    change: (ids: readonly string[]) => readonly string[],
  ): Promise<boolean> {
    const own = this.groups().find((group) => group.establishmentId === establishmentId);
    const ids = change((own?.homes ?? []).map((home) => home.locationId));
    return this.run(async () => {
      if (ids.length === 0) await this.api.clearHome(companyId, productId, establishmentId);
      else await this.api.replaceHomes(companyId, productId, establishmentId, ids);
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
