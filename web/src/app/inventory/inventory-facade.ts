// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { InventoryApi, InventoryRefused } from './inventory-api';
import type { PickAsked } from '../shared/form/pick-api';
import type { ExportFormat } from '../shared/list/export-address';
import type {
  CostBasis,
  InventoryError,
  LocationContents,
  StockDrawingInput,
  StockDrawingRow,
  StockFloorInput,
  StockFloorRow,
  StockLevelRow,
  StockLocationInput,
  StockLocationRow,
  StockMovementInput,
  StockMovementRow,
  StockMovementSearch,
  StockOptions,
  StockProductOption,
  StockVendorOption,
  StockCountInput,
  StockReceiptInput,
  ReceiptCostView,
  StockRepeatInput,
  StockStructureInput,
  StockStructureRow,
  StockOnHand,
  StockSearch,
  StockValuation,
  MapSearch,
} from './inventory-types';

/** How many ids the stock product picker resolves in one question (its `ids` parameter's own bound). */
const PICK_IDS_MOST = 20;

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
  private readonly valuationSignal = signal<StockValuation | null>(null);
  private movementsRequest = 0;
  /** What the movements list last asked for, so a movement arriving elsewhere reads that same page again. */
  private movementsSearch: StockMovementSearch | null = null;
  private readonly floorsSignal = signal<readonly StockFloorRow[]>([]);
  private readonly drawingsSignal = signal<readonly StockDrawingRow[]>([]);
  private drawingsRequest = 0;
  /** Which floor's rectangles are in hand, so a live change reads that same floor again. */
  private drawingsFloorId: string | null = null;
  private readonly structuresSignal = signal<readonly StockStructureRow[]>([]);
  private structuresRequest = 0;
  /** Which floor's building is in hand, so a live change reads that same floor again. */
  private structuresFloorId: string | null = null;
  private readonly contentsSignal = signal<LocationContents | null>(null);
  private contentsRequest = 0;
  private readonly whereaboutsSignal = signal<MapSearch | null>(null);
  private whereaboutsRequest = 0;
  private readonly busySignal = signal(false);
  private reads = 0;
  private readonly errorSignal = signal<InventoryError | null>(null);

  readonly options = this.optionsSignal.asReadonly();
  readonly levels = this.levelsSignal.asReadonly();
  /** How many rows the last search found in all, the page shown being one part of them. */
  readonly total = this.totalSignal.asReadonly();
  readonly locations = this.locationsSignal.asReadonly();
  readonly movements = this.movementsSignal.asReadonly();
  /** How many movements the last search found in all, the page shown being one part of them. */
  readonly movementsTotal = this.movementsTotalSignal.asReadonly();
  /** What the stock is worth; null until read. */
  readonly valuation = this.valuationSignal.asReadonly();
  readonly floors = this.floorsSignal.asReadonly();
  /** What is drawn on the floor being looked at, never on all of them at once. */
  readonly drawings = this.drawingsSignal.asReadonly();
  /** The building on that same floor: its own layer, because nothing on it holds goods. */
  readonly structures = this.structuresSignal.asReadonly();
  /** What the place chosen on the map holds and what has its home there; null while nothing is chosen. */
  readonly contents = this.contentsSignal.asReadonly();
  /** Where the goods searched on the map are; null while nothing is searched. */
  readonly whereabouts = this.whereaboutsSignal.asReadonly();
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

  /** The few vendors a person means while typing, by id the ones a movement names; never marks the screen busy. */
  async pickVendors(companyId: string, asked: PickAsked): Promise<StockVendorOption[]> {
    try {
      return await this.api.pickVendors(companyId, asked);
    } catch (error) {
      this.errorSignal.set(codeOf(error));
      return [];
    }
  }

  /**
   * What a receipt would do to the product's cost, or nothing when it cannot be told: this is a hint beside a form,
   * so a refusal neither marks the screen busy nor fills its error line.
   */
  async receiptCost(
    companyId: string,
    productId: string,
    quantity: string,
    unitCost: string,
  ): Promise<ReceiptCostView | null> {
    try {
      return await this.api.receiptCost(companyId, productId, quantity, unitCost);
    } catch {
      return null;
    }
  }

  /**
   * What the establishment holds of a document's products; none when it cannot be read, since a line is typed the same
   * without it.
   */
  async onHand(
    companyId: string,
    establishmentId: string | null,
    productIds: readonly string[],
  ): Promise<StockOnHand[]> {
    try {
      return await this.api.onHand(companyId, establishmentId, productIds);
    } catch {
      return [];
    }
  }

  exportLevelsUrl(companyId: string, search: StockSearch, format: ExportFormat): string {
    return this.api.exportLevelsUrl(companyId, search, format);
  }

  exportMovementsUrl(companyId: string, search: StockMovementSearch, format: ExportFormat): string {
    return this.api.exportMovementsUrl(companyId, search, format);
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

  async loadValuation(companyId: string): Promise<void> {
    await this.read(async () => this.valuationSignal.set(await this.api.valuation(companyId)));
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

  /** The cost a cost reader enters for a receipt left « à compléter »; true once entered and the movements read again. */
  async enterReceiptCost(
    companyId: string,
    movementId: string,
    unitCost: string,
    applyCost: CostBasis | null,
  ): Promise<boolean> {
    return this.write(
      () => this.api.enterReceiptCost(companyId, movementId, unitCost, applyCost),
      () => this.reloadMovements(companyId),
    );
  }

  /** One delivery shared over several places, stored whole or not at all; true once recorded and the page read again. */
  async receiveSplit(companyId: string, input: StockReceiptInput): Promise<boolean> {
    return this.write(
      () => this.api.receiveSplit(companyId, input),
      async () => {
        const search = this.search;
        if (search !== null) await this.loadStock(companyId, search);
      },
    );
  }

  /** What was found at several places, stored whole or not at all; true once recorded and the page read again. */
  async countSplit(companyId: string, input: StockCountInput): Promise<boolean> {
    return this.write(
      () => this.api.countSplit(companyId, input),
      async () => {
        const search = this.search;
        if (search !== null) await this.loadStock(companyId, search);
      },
    );
  }

  /** Lets an expired lot leave; the page in hand is read again so its row stops saying expired. */
  async releaseLot(companyId: string, lotId: string): Promise<boolean> {
    return this.write(
      () => this.api.releaseLot(companyId, lotId),
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

  /**
   * The plan screen's own context: the floors, the locations a rectangle can be drawn for, and the establishments
   * the floors belong to. The rectangles of the floor being looked at are read separately, because a person moves
   * between floors far more often than the company gains one.
   */
  async loadPlanContext(companyId: string): Promise<void> {
    await this.read(async () => {
      const [options, locations, floors] = await Promise.all([
        this.api.options(companyId),
        this.api.locations(companyId),
        this.api.floors(companyId),
      ]);
      this.optionsSignal.set(options);
      this.locationsSignal.set(locations);
      this.floorsSignal.set(floors);
    });
  }

  /** What is drawn on one floor. Only the latest floor asked for is shown, as the lists do it. */
  async loadDrawings(companyId: string, floorId: string): Promise<void> {
    const request = ++this.drawingsRequest;
    this.drawingsFloorId = floorId;
    await this.read(async () => {
      const drawings = await this.api.drawings(companyId, floorId);
      if (request !== this.drawingsRequest) return;
      this.drawingsSignal.set(drawings);
    });
  }

  /**
   * What a place holds, with every place under it, and what has its home there — for the stock map's « Ce qu'il y a
   * ici ». Only the latest choice's answer is shown: a person going from rack to rack sends one read per press, and
   * they need not come back in order. One page of the stock, the largest the API serves; the panel says when there is
   * more.
   */
  async loadContents(companyId: string, locationId: string | null): Promise<void> {
    const request = ++this.contentsRequest;
    if (locationId === null) {
      this.contentsSignal.set(null);
      return;
    }
    await this.read(async () => {
      const [page, homes] = await Promise.all([
        this.api.levels(companyId, {
          page: 1,
          itemsPerPage: CONTENTS_PAGE,
          q: '',
          locationIds: [locationId],
          establishmentIds: [],
          productIds: [],
          negative: null,
          expired: null,
          intervals: {},
          order: { key: 'location', direction: 'asc' },
        }),
        this.api.locationHomes(companyId, locationId),
      ]);
      if (request !== this.contentsRequest) return;
      this.contentsSignal.set({ locationId, levels: page.rows, total: page.total, homes });
    });
  }

  /** The place in hand, read again: a movement elsewhere may have filled or emptied it. */
  /**
   * Finds goods on the map, latest search only, each product asked answered in the order asked. The words of a
   * product found nowhere are asked by id beside it, a few at a time as the picker takes them, since it answers no
   * row to carry them; one no stock is kept of answers neither, and reads as not found.
   */
  async loadWhereabouts(companyId: string, productIds: readonly string[]): Promise<void> {
    const request = ++this.whereaboutsRequest;
    if (productIds.length === 0) {
      this.whereaboutsSignal.set(null);
      return;
    }
    await this.read(async () => {
      const found = await this.api.whereabouts(companyId, productIds);
      const missing = productIds.filter((id) => !found.some((one) => one.productId === id));
      const named: StockProductOption[] = [];
      for (let at = 0; at < missing.length; at += PICK_IDS_MOST) {
        named.push(
          ...(await this.api.pickProducts(companyId, {
            ids: missing.slice(at, at + PICK_IDS_MOST),
          })),
        );
      }
      if (request !== this.whereaboutsRequest) return;
      this.whereaboutsSignal.set({
        productIds,
        products: productIds.map((id) => {
          const one = found.find((product) => product.productId === id);
          if (one !== undefined) return one;
          const product = named.find((option) => option.id === id);
          return {
            productId: id,
            productReference: product?.reference ?? '',
            productName: product?.name ?? '',
            unitName: '',
            unitDecimals: product?.unitDecimals ?? 3,
            rows: [],
          };
        }),
      });
    });
  }

  async reloadWhereabouts(companyId: string): Promise<void> {
    const productIds = this.whereaboutsSignal()?.productIds ?? [];
    if (productIds.length > 0) await this.loadWhereabouts(companyId, productIds);
  }

  async reloadContents(companyId: string): Promise<void> {
    const locationId = this.contentsSignal()?.locationId ?? null;
    if (locationId !== null) await this.loadContents(companyId, locationId);
  }

  /** The floor in hand, read again: what a live change brought belongs on it or does not. */
  async reloadDrawings(companyId: string): Promise<void> {
    const floorId = this.drawingsFloorId;
    if (floorId !== null) await this.loadDrawings(companyId, floorId);
  }

  async createFloor(companyId: string, input: StockFloorInput): Promise<boolean> {
    return this.write(
      () => this.api.createFloor(companyId, input),
      () => this.reloadFloors(companyId),
    );
  }

  async reviseFloor(companyId: string, id: string, input: StockFloorInput): Promise<boolean> {
    return this.write(
      () => this.api.reviseFloor(companyId, id, input),
      () => this.reloadFloors(companyId),
    );
  }

  /** The floor goes and its rectangles with it; what they were drawn for stays. */
  async deleteFloor(companyId: string, id: string): Promise<boolean> {
    return this.write(
      () => this.api.deleteFloor(companyId, id),
      async () => {
        await this.reloadFloors(companyId);
        if (this.drawingsFloorId === id) {
          this.drawingsFloorId = null;
          this.drawingsSignal.set([]);
        }
        // The building went with the floor, as the API's own cascade does it.
        if (this.structuresFloorId === id) {
          this.structuresFloorId = null;
          this.structuresSignal.set([]);
        }
      },
    );
  }

  /**
   * Draws a location on a floor, or moves the rectangle it already has. The floor's rectangles are read again and
   * so are the floors, because a floor carries how many rectangles it holds.
   */
  async draw(
    companyId: string,
    floorId: string,
    input: StockDrawingInput,
    drawingId: string | null,
  ): Promise<boolean> {
    return this.write(
      () =>
        drawingId === null
          ? this.api.draw(companyId, floorId, input)
          : this.api.moveDrawing(companyId, drawingId, input),
      () => this.afterDrawing(companyId, floorId),
    );
  }

  async eraseDrawing(companyId: string, floorId: string, drawingId: string): Promise<boolean> {
    return this.write(
      () => this.api.eraseDrawing(companyId, drawingId),
      () => this.afterDrawing(companyId, floorId),
    );
  }

  /**
   * Repeats a rectangle down an aisle. It creates stock LOCATIONS as well as rectangles, so the locations are read
   * again with the floor — otherwise the new racks would be missing from every picker on the screen that just made
   * them, including the one the next rectangle would be drawn for.
   */
  async repeatDrawing(
    companyId: string,
    floorId: string,
    drawingId: string,
    input: StockRepeatInput,
  ): Promise<boolean> {
    return this.write(
      () => this.api.repeatDrawing(companyId, drawingId, input),
      async () => {
        await Promise.all([this.afterDrawing(companyId, floorId), this.reloadLocations(companyId)]);
      },
    );
  }

  /**
   * The building on one floor. Only the latest floor asked for is shown, as the drawings do it: a person moves
   * between floors faster than two reads come back.
   */
  async loadStructures(companyId: string, floorId: string): Promise<void> {
    const request = ++this.structuresRequest;
    this.structuresFloorId = floorId;
    await this.read(async () => {
      const structures = await this.api.structures(companyId, floorId);
      if (request !== this.structuresRequest) return;
      this.structuresSignal.set(structures);
    });
  }

  /** The floor in hand, read again: what a live change brought belongs on it or does not. */
  async reloadStructures(companyId: string): Promise<void> {
    const floorId = this.structuresFloorId;
    if (floorId !== null) await this.loadStructures(companyId, floorId);
  }

  /**
   * Draws a piece of the building, or corrects one. Only the structure is read again: a wall changes no floor's
   * rectangle count, because a wall is not a rectangle of stock.
   */
  async buildStructure(
    companyId: string,
    floorId: string,
    input: StockStructureInput,
    structureId: string | null,
  ): Promise<boolean> {
    return this.write(
      () =>
        structureId === null
          ? this.api.buildStructure(companyId, floorId, input)
          : this.api.reshapeStructure(companyId, structureId, input),
      () => this.loadStructures(companyId, floorId),
    );
  }

  async eraseStructure(companyId: string, floorId: string, structureId: string): Promise<boolean> {
    return this.write(
      () => this.api.eraseStructure(companyId, structureId),
      () => this.loadStructures(companyId, floorId),
    );
  }

  clearError(): void {
    this.errorSignal.set(null);
  }

  /** A rectangle written changes the floor's own count as well as what is on it, so both are read again. */
  private async afterDrawing(companyId: string, floorId: string): Promise<void> {
    await Promise.all([this.loadDrawings(companyId, floorId), this.reloadFloors(companyId)]);
  }

  private async reloadFloors(companyId: string): Promise<void> {
    this.floorsSignal.set(await this.api.floors(companyId));
  }

  private async reloadLocations(companyId: string): Promise<void> {
    this.locationsSignal.set(await this.api.locations(companyId));
  }

  private async read(load: () => Promise<void>): Promise<void> {
    this.reads++;
    this.busySignal.set(true);
    // Cleared when a read starts, never when one ends, so a read answering after another failed does not hide it.
    this.errorSignal.set(null);
    try {
      await load();
    } catch (error) {
      this.errorSignal.set(codeOf(error));
    } finally {
      // Several reads run at once (a list and what its filters name): busy until the last one answers.
      if (--this.reads === 0) this.busySignal.set(false);
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

/** The most rows of stock the panel of a place reads at once: the API's own largest page. */
const CONTENTS_PAGE = 100;

function codeOf(error: unknown): InventoryError {
  return error instanceof InventoryRefused ? error.code : 'network';
}
