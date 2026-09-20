// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { InventoryApi, InventoryRefused } from './inventory-api';
import type { PickAsked } from '../shared/form/pick-api';
import type {
  InventoryError,
  StockLevelRow,
  StockLocationInput,
  StockLocationRow,
  StockMovementInput,
  StockMovementRow,
  StockMovementSearch,
  StockOptions,
  StockProductOption,
  StockSearch,
} from './inventory-types';

/** The stock of the company being worked in: what is on hand, how it moved, where it is kept, and the forms' options. */
@Injectable({ providedIn: 'root' })
export class InventoryFacade {
  private readonly api = inject(InventoryApi);
  private readonly optionsSignal = signal<StockOptions | null>(null);
  private readonly levelsSignal = signal<readonly StockLevelRow[]>([]);
  private readonly totalSignal = signal(0);
  private pageRequest = 0;
  /** What the list last asked for, so recording a movement reads that same page again. */
  private search: StockSearch | null = null;
  private readonly locationsSignal = signal<readonly StockLocationRow[]>([]);
  private readonly movementsSignal = signal<readonly StockMovementRow[]>([]);
  private readonly movementsTotalSignal = signal(0);
  private movementsRequest = 0;
  /** What the movements list last asked for, so a movement arriving elsewhere reads that same page again. */
  private movementsSearch: StockMovementSearch | null = null;
  private readonly busySignal = signal(false);
  private readonly errorSignal = signal<InventoryError | null>(null);

  readonly options = this.optionsSignal.asReadonly();
  readonly levels = this.levelsSignal.asReadonly();
  /** How many rows the last search found in all, the page shown being one part of them. */
  readonly total = this.totalSignal.asReadonly();
  readonly locations = this.locationsSignal.asReadonly();
  readonly movements = this.movementsSignal.asReadonly();
  /** How many movements the last search found in all, the page shown being one part of them. */
  readonly movementsTotal = this.movementsTotalSignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();

  /** What the stock screen needs besides its page: what a movement may be recorded against. */
  async loadStockContext(companyId: string): Promise<void> {
    await this.read(async () => {
      const [options, locations] = await Promise.all([
        this.api.options(companyId),
        this.api.locations(companyId),
      ]);
      this.optionsSignal.set(options);
      this.locationsSignal.set(locations);
    });
  }

  /**
   * The few stocked products a person means while typing, and — by id — the ones a movement already names, stocked
   * or not. A search that fails answers nothing and says so in `error`, rather than reading as "nothing found":
   * what the picker could not ask for is not the same as what does not exist.
   */
  async pickProducts(companyId: string, asked: PickAsked): Promise<StockProductOption[]> {
    // A picker never marks the screen busy, because a person is typing while it runs.
    try {
      return await this.api.pickProducts(companyId, asked);
    } catch (error) {
      this.errorSignal.set(codeOf(error));
      return [];
    }
  }

  /**
   * One page of the stock the search finds. Only the latest search's answer is shown: typing sends one search per
   * keystroke and they need not come back in order.
   */
  async loadStock(companyId: string, search: StockSearch): Promise<void> {
    const request = ++this.pageRequest;
    this.search = search;
    await this.read(async () => {
      const page = await this.api.levels(companyId, search);
      if (request !== this.pageRequest) return;
      this.levelsSignal.set(page.rows);
      this.totalSignal.set(page.total);
    });
  }

  /**
   * One page of the movements the search finds. Only the latest search's answer is shown, for the same reason the
   * stock list does it: typing sends one search per keystroke and they need not come back in order.
   */
  async loadMovements(companyId: string, search: StockMovementSearch): Promise<void> {
    const request = ++this.movementsRequest;
    this.movementsSearch = search;
    await this.read(async () => {
      const page = await this.api.movements(companyId, search);
      if (request !== this.movementsRequest) return;
      this.movementsSignal.set(page.rows);
      this.movementsTotalSignal.set(page.total);
    });
  }

  /** The movements page in hand, read again: what a live change brought belongs on that page or does not. */
  async reloadMovements(companyId: string): Promise<void> {
    const search = this.movementsSearch;
    if (search !== null) await this.loadMovements(companyId, search);
  }

  /** What the locations and movements screens need besides their rows: the locations a row names, and the options. */
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
      // The page in hand is read again, not the whole stock: what was just recorded belongs on it or does not.
      async () => {
        const search = this.search;
        if (search !== null) await this.loadStock(companyId, search);
      },
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
