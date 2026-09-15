// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { InventoryApi, InventoryRefused } from './inventory-api';
import type {
  InventoryError,
  StockLevelRow,
  StockLocationInput,
  StockLocationRow,
  StockMovementInput,
  StockMovementRow,
  StockOptions,
} from './inventory-types';

/** The stock of the company being worked in: what is on hand, how it moved, where it is kept, and the forms' options. */
@Injectable({ providedIn: 'root' })
export class InventoryFacade {
  private readonly api = inject(InventoryApi);
  private readonly optionsSignal = signal<StockOptions | null>(null);
  private readonly levelsSignal = signal<readonly StockLevelRow[]>([]);
  private readonly locationsSignal = signal<readonly StockLocationRow[]>([]);
  private readonly movementsSignal = signal<readonly StockMovementRow[]>([]);
  private readonly busySignal = signal(false);
  private readonly errorSignal = signal<InventoryError | null>(null);

  readonly options = this.optionsSignal.asReadonly();
  readonly levels = this.levelsSignal.asReadonly();
  readonly locations = this.locationsSignal.asReadonly();
  readonly movements = this.movementsSignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();

  async loadStock(companyId: string): Promise<void> {
    await this.read(async () => {
      const [options, levels, locations] = await Promise.all([
        this.api.options(companyId),
        this.api.levels(companyId),
        this.api.locations(companyId),
      ]);
      this.optionsSignal.set(options);
      this.levelsSignal.set(levels);
      this.locationsSignal.set(locations);
    });
  }

  /** One product's movements, or the company's latest; the levels name a product the options no longer offer. */
  async loadMovements(companyId: string, productId: string | null): Promise<void> {
    await this.read(async () => {
      const [options, levels, locations, movements] = await Promise.all([
        this.api.options(companyId),
        this.api.levels(companyId),
        this.api.locations(companyId),
        this.api.movements(companyId, productId),
      ]);
      this.optionsSignal.set(options);
      this.levelsSignal.set(levels);
      this.locationsSignal.set(locations);
      this.movementsSignal.set(movements);
    });
  }

  async loadLocations(companyId: string): Promise<void> {
    await this.read(async () => {
      const [options, locations] = await Promise.all([
        this.api.options(companyId),
        this.api.locations(companyId),
      ]);
      this.optionsSignal.set(options);
      this.locationsSignal.set(locations);
    });
  }

  /** A receipt or a count; true once recorded and the stock read again, false with the reason in `error`. */
  async record(companyId: string, input: StockMovementInput): Promise<boolean> {
    return this.write(
      () => this.api.record(companyId, input),
      async () => this.levelsSignal.set(await this.api.levels(companyId)),
    );
  }

  async createLocation(companyId: string, input: StockLocationInput): Promise<boolean> {
    return this.write(
      () => this.api.createLocation(companyId, input),
      () => this.reloadLocations(companyId),
    );
  }

  async reviseLocation(companyId: string, id: string, input: StockLocationInput): Promise<boolean> {
    return this.write(
      () => this.api.reviseLocation(companyId, id, input),
      () => this.reloadLocations(companyId),
    );
  }

  async deleteLocation(companyId: string, id: string): Promise<boolean> {
    return this.write(
      () => this.api.deleteLocation(companyId, id),
      () => this.reloadLocations(companyId),
    );
  }

  clearError(): void {
    this.errorSignal.set(null);
  }

  private async reloadLocations(companyId: string): Promise<void> {
    this.locationsSignal.set(await this.api.locations(companyId));
  }

  private async read(load: () => Promise<void>): Promise<void> {
    this.busySignal.set(true);
    try {
      await load();
      this.errorSignal.set(null);
    } catch (error) {
      this.errorSignal.set(codeOf(error));
    } finally {
      this.busySignal.set(false);
    }
  }

  private async write(call: () => Promise<unknown>, reload: () => Promise<void>): Promise<boolean> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      await call();
      await reload();
      return true;
    } catch (error) {
      this.errorSignal.set(codeOf(error));
      return false;
    } finally {
      this.busySignal.set(false);
    }
  }
}

function codeOf(error: unknown): InventoryError {
  return error instanceof InventoryRefused ? error.code : 'network';
}
