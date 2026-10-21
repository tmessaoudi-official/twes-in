// SPDX-License-Identifier: AGPL-3.0-or-later

import { requestState } from '../shared/feedback/request-state';
import { computed, inject, Injectable, signal } from '@angular/core';
import { InventoryApi } from '../inventory/inventory-api';
import type { StockLocationRow } from '../inventory/inventory-types';
import { ProductsApi, ProductsRefused } from './products-api';
import { ProductsFacade } from './products-facade';
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
/** A homes write: saved, refused with the reason kept, or refused as made over a product saved since. */
export type HomesWrite = 'saved' | 'refused' | 'changed';

@Injectable()
export class ProductHomes {
  private readonly api = inject(ProductsApi);
  private readonly stock = inject(InventoryApi);
  private readonly products = inject(ProductsFacade);
  private readonly homesSignal = signal<readonly ProductHomeRow[]>([]);
  private readonly locationsSignal = signal<readonly StockLocationRow[]>([]);
  private readonly state = requestState<ProductsError>((error) =>
    error instanceof ProductsRefused ? error.code : 'network',
  );

  readonly homes = this.homesSignal.asReadonly();
  readonly locations = this.locationsSignal.asReadonly();
  readonly busy = this.state.busy;
  readonly error = this.state.error;

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
    }, true);
  }

  /** Where it lives, establishment by establishment, each in its order: the first of each is the main home. */
  readonly groups = computed(() => groupByEstablishment(this.homesSignal()));

  /**
   * A home added after the others of its establishment, which a location knows; its first one is the main one. Every
   * write names the product's version the homes were read at: they are shown beside the product form and move it.
   */
  async add(
    companyId: string,
    productId: string,
    locationId: string,
    version: number,
  ): Promise<HomesWrite> {
    const place = this.locationsSignal().find((location) => location.id === locationId);
    if (place === undefined) {
      // refusal-answer: none, the place was refused here before anything was sent.
      this.state.fail(new ProductsRefused('invalid'));
      return 'refused';
    }
    return this.write(companyId, productId, place.establishmentId, version, (ids) =>
      appended(ids, locationId),
    );
  }

  /** A home one step towards the main end (−1) or away from it (1) in its own establishment. */
  async move(
    companyId: string,
    productId: string,
    home: ProductHomeRow,
    step: -1 | 1,
    version: number,
  ): Promise<HomesWrite> {
    return this.write(companyId, productId, home.establishmentId, version, (ids) =>
      moved(ids, home.locationId, step),
    );
  }

  /** A place stops being a home; the last one of an establishment clears it, the API having no empty list. */
  async remove(
    companyId: string,
    productId: string,
    home: ProductHomeRow,
    version: number,
  ): Promise<HomesWrite> {
    return this.write(companyId, productId, home.establishmentId, version, (ids) =>
      without(ids, home.locationId),
    );
  }

  /**
   * The establishment's homes as a new list, written whole (an empty one clears them) and read again: the API keeps
   * the order. The version the write left is the product's before this says it is done, so a click straight after
   * sends it. A product saved since refuses the list and the homes are read again, which the product page says as it
   * reads the product again.
   */
  private async write(
    companyId: string,
    productId: string,
    establishmentId: string,
    version: number,
    change: (ids: readonly string[]) => readonly string[],
  ): Promise<HomesWrite> {
    const own = this.groups().find((group) => group.establishmentId === establishmentId);
    const ids = change((own?.homes ?? []).map((home) => home.locationId));
    const done = await this.run(async () => {
      this.products.versionMoved(
        productId,
        await this.api.replaceHomes(companyId, productId, establishmentId, ids, version),
      );
      this.homesSignal.set(await this.api.homes(companyId, productId));
    });
    if (done) return 'saved';
    if (this.state.error() !== 'record_changed') return 'refused';
    this.state.clearError();
    await this.run(async () => this.homesSignal.set(await this.api.homes(companyId, productId)));
    return 'changed';
  }

  /** `reading` marks a read: one nobody asked for leaves busy as it is (QuietReads); a write is always marked. */
  private async run(work: () => Promise<void>, reading = false): Promise<boolean> {
    return reading ? this.state.read(work) : this.state.write(work);
  }
}
