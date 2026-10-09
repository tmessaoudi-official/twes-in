// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpContext, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  ApiCompaniesCompanyIdstockLevelsGetCollectionResponse,
  LocationHomeLocationHomeRead,
  ApiCompaniesCompanyIdstockMovementsGetCollectionResponse,
  RepeatStockDrawingRepeatStockDrawingReadStockDrawingReadValidationRepeatStockDrawingWrite as RepeatStockDrawingRepeatStockDrawingReadStockDrawingRead,
  RepeatStockDrawingRepeatStockDrawingWriteValidationRepeatStockDrawingWrite as RepeatStockDrawingRepeatStockDrawingWrite,
  StockDrawingStockDrawingRead,
  StockHoldingStockHoldingRead,
  StockDrawingStockDrawingWriteValidationStockDrawingWrite as StockDrawingStockDrawingWrite,
  StockFloorStockFloorRead,
  StockFloorStockFloorWriteValidationStockFloorWrite as StockFloorStockFloorWrite,
  StockLevelJsonldStockLevelRead,
  StockMovementJsonldStockMovementRead,
  StockLocationStockLocationRead,
  StockLocationStockLocationWriteValidationStockLocationWrite as StockLocationStockLocationWrite,
  StockMovementStockMovementReadValidationStockMovementWrite as StockMovementStockMovementRead,
  StockMovementStockMovementWriteValidationStockMovementWrite as StockMovementStockMovementWrite,
  StockValuationStockValuationRead,
  StockOptionsStockOptionsRead,
  StockStructureStockStructureRead,
  StockStructureStockStructureWriteValidationStockStructureWrite as StockStructureStockStructureWrite,
  StockProductPickStockProductPickRead,
  StockVendorPickStockVendorPickRead,
  StockWhereaboutStockWhereaboutRead,
  StockReceiptStockReceiptReadValidationStockReceiptWrite as StockReceiptStockReceiptRead,
  StockCountStockCountReadValidationStockCountWrite as StockCountStockCountRead,
  StockCountStockCountWriteValidationStockCountWrite as StockCountStockCountWrite,
  StockReceiptStockReceiptWriteValidationStockReceiptWrite as StockReceiptStockReceiptWrite,
  ReceiptCostReceiptCostRead,
  StockOnHandStockOnHandRead,
  ReceiptCostEntryReceiptCostEntryWriteValidationReceiptCostEntryWrite as ReceiptCostEntryWrite,
} from '../api/types.gen';
import { type ExportFormat, exportAddress } from '../shared/list/export-address';
import { apiRangeKey } from '../shared/list/list-filters';
import type { ListPage } from '../shared/list/list-types';
import { type PickAsked, pickParams } from '../shared/form/pick-api';
import {
  type CostBasis,
  type InventoryError,
  type LocationHomeRow,
  type Whereabouts,
  type StockDrawingInput,
  type StockDrawingRow,
  type StructureKind,
  type StockStructureInput,
  type StockStructureRow,
  type StockRepeatInput,
  type StockFloorInput,
  type StockFloorRow,
  STOCK_LOCATION_KINDS,
  STRUCTURE_KINDS,
  STOCK_MOVEMENT_KINDS,
  STOCK_LOSS_REASONS,
  STOCK_SOURCE_TYPES,
  type StockLevelRow,
  type StockLocationInput,
  type StockLocationRow,
  type StockMovementInput,
  type StockCountInput,
  type StockReceiptInput,
  type StockMovementRow,
  API_DECIMALS,
  type StockOptions,
  type StockProductOption,
  type StockVendorOption,
  type ReceiptCostView,
  type StockMovementSearch,
  type StockSearch,
  type StockOnHand,
  type StockValuation,
} from './inventory-types';
import { SILENT } from '../shared/feedback/activity-interceptor';

/** How many products one question about stock on hand may name (`StockOnHandResource::MOST`). */
const ON_HAND_MOST = 100;

/** Thrown when the API refuses; carries the code the UI translates. */
export class InventoryRefused extends Error {
  constructor(readonly code: InventoryError) {
    super(code);
  }
}

/** The HTTP edge of the inventory feature: the only code here that knows endpoints and generated types. */
@Injectable({ providedIn: 'root' })
export class InventoryApi {
  private readonly http = inject(HttpClient);

  async options(companyId: string): Promise<StockOptions> {
    return this.guard(async () =>
      toOptions(
        await firstValueFrom(
          this.http.get<StockOptionsStockOptionsRead>(path(companyId, 'stock-options')),
        ),
      ),
    );
  }

  /**
   * The few stocked products a person means, or — given ids — exactly the ones a movement already names, whether or
   * not stock is still kept of them. The catalogue is never read whole (docs/SPEC.md § 7, 2026-09-17, ruling 3).
   */
  async pickProducts(companyId: string, asked: PickAsked): Promise<StockProductOption[]> {
    return this.guard(async () => {
      const rows = await firstValueFrom(
        this.http.get<StockProductPickStockProductPickRead[]>(
          `${path(companyId, 'stock-options')}/products`,
          { params: pickParams(asked) },
        ),
      );
      return rows.map((product) => ({
        id: product.id ?? '',
        reference: product.reference,
        name: product.name,
        unitCode: product.unitCode,
        unitDecimals: product.unitDecimals,
        homeLocationId: product.homeLocationId ?? null,
        tracking: product.tracking ?? 'none',
      }));
    });
  }

  /**
   * What the establishment's shelves hold of the first hundred products a document's lines name, for those whose stock is kept; the
   * main establishment's when none is named. Asked in the background while lines are typed, so it shows no activity.
   */
  async onHand(
    companyId: string,
    establishmentId: string | null,
    productIds: readonly string[],
  ): Promise<StockOnHand[]> {
    if (productIds.length === 0) return [];
    return this.guard(async () => {
      let params = new HttpParams();
      // The API takes at most this many at once: a longer document is told of its first hundred.
      for (const id of productIds.slice(0, ON_HAND_MOST)) params = params.append('ids[]', id);
      if (establishmentId !== null) params = params.set('establishmentId', establishmentId);
      const answer = await firstValueFrom(
        this.http.get<StockOnHandStockOnHandRead>(`${path(companyId, 'stock-options')}/on-hand`, {
          params,
          context: new HttpContext().set(SILENT, true),
        }),
      );
      return (answer.items ?? []).map((item) => ({
        productId: item.productId,
        unitId: item.unitId,
        onHand: item.onHand,
      }));
    });
  }

  /**
   * The few vendors a person means, or — given ids — exactly the ones a movement names. A person without the right to
   * receive stock is answered 404 and reads as none; the book is never read whole.
   */
  async pickVendors(companyId: string, asked: PickAsked): Promise<StockVendorOption[]> {
    return this.guard(async () => {
      const rows = await firstValueFrom(
        this.http.get<StockVendorPickStockVendorPickRead[]>(
          `${path(companyId, 'stock-options')}/vendors`,
          { params: pickParams(asked) },
        ),
      );
      return rows.map((vendor) => ({
        id: vendor.id ?? '',
        number: vendor.number,
        name: vendor.name,
      }));
    });
  }

  /**
   * What a receipt of the product would do to its cost, before it is saved: a quantity or a cost that is not typed yet
   * is simply left out of the question. A person who may not read costs is answered 404, which reads as no answer.
   */
  async receiptCost(
    companyId: string,
    productId: string,
    quantity: string,
    unitCost: string,
  ): Promise<ReceiptCostView> {
    return this.guard(async () => {
      let params = new HttpParams().set('productId', productId);
      if (quantity !== '') params = params.set('quantity', quantity);
      if (unitCost !== '') params = params.set('unitCost', unitCost);
      const cost = await firstValueFrom(
        this.http.get<ReceiptCostReceiptCostRead>(
          `${path(companyId, 'stock-options')}/receipt-cost`,
          {
            params,
          },
        ),
      );
      return {
        mode: cost.mode ?? 'suggest',
        costNow: cost.costNow ?? null,
        average: cost.average ?? null,
        lastCost: cost.lastCost ?? null,
        lastAt: cost.lastAt ?? null,
      };
    });
  }

  /** One page of the stock the company holds, grouped, searched, narrowed and sorted by the API. */
  async levels(companyId: string, search: StockSearch): Promise<ListPage<StockLevelRow>> {
    return this.guard(async () => {
      const page = await firstValueFrom(
        this.http.get<ApiCompaniesCompanyIdstockLevelsGetCollectionResponse>(
          path(companyId, 'stock-levels'),
          { headers: { Accept: 'application/ld+json' }, params: toSearchParams(search) },
        ),
      );
      if (page.totalItems === undefined) throw new Error('A page of stock came without its total.');
      return { rows: page.member.map(toLevel), total: page.totalItems };
    });
  }

  /** The goods whose home is this place or a place under it, for the stock map's « Ce qu'il y a ici ». */
  async locationHomes(companyId: string, locationId: string): Promise<LocationHomeRow[]> {
    return this.guard(async () =>
      (
        await firstValueFrom(
          this.http.get<LocationHomeLocationHomeRead[]>(
            `${path(companyId, 'stock-locations', locationId)}/homes`,
          ),
        )
      ).map((raw) => ({
        productId: raw.productId ?? '',
        productReference: raw.productReference ?? '',
        productName: raw.productName ?? '',
        locationId: raw.locationId ?? '',
        locationCode: raw.locationCode ?? '',
        main: raw.main ?? false,
      })),
    );
  }

  /**
   * Where goods are, as the map lights them: one answer per product that is somewhere, in the order asked. A product
   * found nowhere answers no row, so it is absent here and the caller, which knows what it asked for, says so.
   */
  async whereabouts(companyId: string, productIds: readonly string[]): Promise<Whereabouts[]> {
    if (productIds.length === 0) return [];
    return this.guard(async () => {
      let params = new HttpParams();
      for (const id of productIds) params = params.append('productId[]', id);
      const raw = await firstValueFrom(
        this.http.get<StockWhereaboutStockWhereaboutRead[]>(path(companyId, 'stock-whereabouts'), {
          params,
        }),
      );
      const found = new Map<string, Whereabouts>();
      for (const row of raw) {
        const product = found.get(row.productId) ?? {
          productId: row.productId,
          productReference: row.productReference,
          productName: row.productName,
          unitName: row.unitName,
          unitDecimals: row.unitDecimals,
          rows: [],
        };
        found.set(row.productId, {
          ...product,
          rows: [
            ...product.rows,
            {
              floorId: row.floorId ?? null,
              locationId: row.locationId ?? null,
              locationCode: row.locationCode ?? null,
              locationName: row.locationName ?? null,
              quantity: row.quantity,
              lines: row.lines.map((line) => ({
                locationId: line.locationId,
                locationCode: line.locationCode,
                locationName: line.locationName,
                quantity: line.quantity,
              })),
            },
          ],
        });
      }
      return [...found.values()];
    });
  }

  /** Every location of the company; reading them gives each establishment its default location. */
  async locations(companyId: string): Promise<StockLocationRow[]> {
    return this.guard(async () =>
      (
        await firstValueFrom(
          this.http.get<StockLocationStockLocationRead[]>(path(companyId, 'stock-locations')),
        )
      ).map(toLocation),
    );
  }

  /** Where the stock levels the search finds are downloaded as a file, every page of them. */
  exportLevelsUrl(companyId: string, search: StockSearch, format: ExportFormat): string {
    return exportAddress(companyId, 'stock-levels', toSearchParams(search), format);
  }

  /** Where the movements the search finds are downloaded as a file, every page of them. */
  exportMovementsUrl(companyId: string, search: StockMovementSearch, format: ExportFormat): string {
    return exportAddress(companyId, 'stock-movements', toMovementParams(search), format);
  }

  /** One page of the company's movements, narrowed, sorted and paged by the API; newest first by default. */
  async movements(
    companyId: string,
    search: StockMovementSearch,
  ): Promise<ListPage<StockMovementRow>> {
    return this.guard(async () => {
      const page = await firstValueFrom(
        this.http.get<ApiCompaniesCompanyIdstockMovementsGetCollectionResponse>(
          path(companyId, 'stock-movements'),
          { headers: { Accept: 'application/ld+json' }, params: toMovementParams(search) },
        ),
      );
      if (page.totalItems === undefined)
        throw new Error('A page of movements came without its total.');
      return { rows: page.member.map(toMovement), total: page.totalItems };
    });
  }

  /** What the stock is worth, per product and in all; needs the right to read what things cost. */
  async valuation(companyId: string): Promise<StockValuation> {
    return this.guard(async () => {
      const raw = await firstValueFrom(
        this.http.get<StockValuationStockValuationRead>(path(companyId, 'stock-valuation')),
      );
      return {
        total: raw.total ?? '0.000',
        estimated: raw.estimated ?? false,
        lines: (raw.lines ?? []).map((line) => ({
          productId: String(line['productId'] ?? ''),
          productReference: String(line['productReference'] ?? ''),
          productName: String(line['productName'] ?? ''),
          unitCode: String(line['unitCode'] ?? ''),
          unitName: String(line['unitName'] ?? ''),
          quantity: String(line['quantity'] ?? '0.000'),
          unitCost: line['unitCost'] == null ? null : String(line['unitCost']),
          value: String(line['value'] ?? '0.000'),
          unvaluedQuantity: String(line['unvaluedQuantity'] ?? '0.000'),
          estimatedQuantity: String(line['estimatedQuantity'] ?? '0.000'),
        })),
      };
    });
  }

  /** 409 when another location of the establishment has the code; 422 naming the field the API refused. */
  async createLocation(companyId: string, input: StockLocationInput): Promise<StockLocationRow> {
    const body: StockLocationStockLocationWrite = { ...input };
    return this.guard(
      async () =>
        toLocation(
          await firstValueFrom(
            this.http.post<StockLocationStockLocationRead>(
              path(companyId, 'stock-locations'),
              body,
            ),
          ),
        ),
      'code_taken',
    );
  }

  async reviseLocation(
    companyId: string,
    id: string,
    input: StockLocationInput,
  ): Promise<StockLocationRow> {
    const body: StockLocationStockLocationWrite = { ...input };
    return this.guard(
      async () =>
        toLocation(
          await firstValueFrom(
            this.http.put<StockLocationStockLocationRead>(
              path(companyId, 'stock-locations', id),
              body,
            ),
          ),
        ),
      'code_taken',
    );
  }

  /** 409 for a default location, or one that still holds a location or a movement. */
  async deleteLocation(companyId: string, id: string): Promise<void> {
    await this.guard(
      async () => firstValueFrom(this.http.delete(path(companyId, 'stock-locations', id))),
      'in_use',
    );
  }

  /** Every floor the company's stock is drawn on, each carrying how many rectangles it already holds. */
  async floors(companyId: string): Promise<StockFloorRow[]> {
    return this.guard(async () =>
      (
        await firstValueFrom(
          this.http.get<StockFloorStockFloorRead[]>(path(companyId, 'stock-floors')),
        )
      ).map(toFloor),
    );
  }

  /** 409 when another floor of the same establishment is already at that level. */
  async createFloor(companyId: string, input: StockFloorInput): Promise<StockFloorRow> {
    return this.guard(
      async () =>
        toFloor(
          await firstValueFrom(
            this.http.post<StockFloorStockFloorRead>(
              path(companyId, 'stock-floors'),
              floorBody(input),
            ),
          ),
        ),
      'level_taken',
    );
  }

  /** A floor's name, its level and the plan behind it are one form, so they are one request. */
  async reviseFloor(companyId: string, id: string, input: StockFloorInput): Promise<StockFloorRow> {
    return this.guard(
      async () =>
        toFloor(
          await firstValueFrom(
            this.http.put<StockFloorStockFloorRead>(
              path(companyId, 'stock-floors', id),
              floorBody(input),
            ),
          ),
        ),
      'level_taken',
    );
  }

  /** Lets an expired lot leave after all; a lot still in date is refused. */
  async releaseLot(companyId: string, lotId: string): Promise<void> {
    await this.guard(async () =>
      firstValueFrom(this.http.post(`${path(companyId, 'stock-lots', lotId)}/release`, null)),
    );
  }

  /** The rectangles go with the floor; what they were drawn for keeps its code, its tree and its stock. */
  async deleteFloor(companyId: string, id: string): Promise<void> {
    await this.guard(
      async () => firstValueFrom(this.http.delete(path(companyId, 'stock-floors', id))),
      'in_use',
    );
  }

  /** What is drawn on one floor. Only bound rectangles come back: an unlabelled one is on no screen. */
  async drawings(companyId: string, floorId: string): Promise<StockDrawingRow[]> {
    return this.guard(async () =>
      (
        await firstValueFrom(
          this.http.get<StockDrawingStockDrawingRead[]>(
            `${path(companyId, 'stock-floors', floorId)}/drawings`,
          ),
        )
      ).map(toDrawing),
    );
  }

  /** How many products each drawn place of a floor holds, places holding nothing left out. */
  async holdings(companyId: string, floorId: string): Promise<ReadonlyMap<string, number>> {
    return this.guard(
      async () =>
        new Map(
          (
            await firstValueFrom(
              this.http.get<StockHoldingStockHoldingRead[]>(
                `${path(companyId, 'stock-floors', floorId)}/holdings`,
              ),
            )
          ).map((raw) => [raw.locationId ?? '', raw.products ?? 0] as const),
        ),
    );
  }

  /**
   * Draws a location on a floor. Drawing one that is already drawn moves it — a rack is in one place — and a
   * location the plan does not carry, a bin, answers 422 naming `locationId`.
   */
  async draw(
    companyId: string,
    floorId: string,
    input: StockDrawingInput,
  ): Promise<StockDrawingRow> {
    return this.guard(async () =>
      toDrawing(
        await firstValueFrom(
          this.http.post<StockDrawingStockDrawingRead>(
            `${path(companyId, 'stock-floors', floorId)}/drawings`,
            drawingBody(input),
          ),
        ),
      ),
    );
  }

  /**
   * Moves or resizes a rectangle by its own address, and says which location it is drawn for: a rectangle never
   * changes floor, because goods do not climb — that is another rectangle.
   */
  async moveDrawing(
    companyId: string,
    drawingId: string,
    input: StockDrawingInput,
  ): Promise<StockDrawingRow> {
    return this.guard(async () =>
      toDrawing(
        await firstValueFrom(
          this.http.put<StockDrawingStockDrawingRead>(
            path(companyId, 'stock-drawings', drawingId),
            drawingBody(input),
          ),
        ),
      ),
    );
  }

  /**
   * Repeats a rectangle down an aisle: N more of it AND the stock locations they are, in one call, because it is one
   * decision. It answers what it created, so the screen never has to read the floor back to learn what it had just
   * asked for — a read that would race anyone else drawing on the same plan.
   *
   * A code already taken answers 409 naming it; a copy stepping off the floor 422 naming the side it left by.
   */
  async repeatDrawing(
    companyId: string,
    drawingId: string,
    input: StockRepeatInput,
  ): Promise<StockDrawingRow[]> {
    const body: RepeatStockDrawingRepeatStockDrawingWrite = { ...input };

    return this.guard(
      async () =>
        (
          await firstValueFrom(
            this.http.post<RepeatStockDrawingRepeatStockDrawingReadStockDrawingRead>(
              `${path(companyId, 'stock-drawings', drawingId)}/repeat`,
              body,
            ),
          )
        ).drawings?.map(toDrawing) ?? [],
    );
  }

  /** The rectangle is erased; the location it was drawn for is untouched. */
  async eraseDrawing(companyId: string, drawingId: string): Promise<void> {
    await this.guard(async () =>
      firstValueFrom(this.http.delete(path(companyId, 'stock-drawings', drawingId))),
    );
  }

  /** The building drawn on one floor. It names no location: nothing on this layer holds goods. */
  async structures(companyId: string, floorId: string): Promise<StockStructureRow[]> {
    return this.guard(async () =>
      (
        await firstValueFrom(
          this.http.get<StockStructureStockStructureRead[]>(
            `${path(companyId, 'stock-floors', floorId)}/structures`,
          ),
        )
      ).map(toStructure),
    );
  }

  /** Draws a piece of the building on a floor. A measurement out of bounds answers 422 naming the field. */
  async buildStructure(
    companyId: string,
    floorId: string,
    input: StockStructureInput,
  ): Promise<StockStructureRow> {
    return this.guard(async () =>
      toStructure(
        await firstValueFrom(
          this.http.post<StockStructureStockStructureRead>(
            `${path(companyId, 'stock-floors', floorId)}/structures`,
            structureBody(input),
          ),
        ),
      ),
    );
  }

  /**
   * Corrects a piece by its own address — what it IS as well as where it stands, because a doorway traced with
   * the wall tool is right in every measurement and wrong in exactly one field.
   */
  async reshapeStructure(
    companyId: string,
    structureId: string,
    input: StockStructureInput,
  ): Promise<StockStructureRow> {
    return this.guard(async () =>
      toStructure(
        await firstValueFrom(
          this.http.put<StockStructureStockStructureRead>(
            path(companyId, 'stock-structures', structureId),
            structureBody(input),
          ),
        ),
      ),
    );
  }

  async eraseStructure(companyId: string, structureId: string): Promise<void> {
    await this.guard(async () =>
      firstValueFrom(this.http.delete(path(companyId, 'stock-structures', structureId))),
    );
  }

  /** 422 naming the field refused: a product whose stock is not kept, a quantity finer than its unit. */
  async record(companyId: string, input: StockMovementInput): Promise<StockMovementRow> {
    const body: StockMovementStockMovementWrite = { ...input };
    return this.guard(async () =>
      toMovement(
        await firstValueFrom(
          this.http.post<StockMovementStockMovementRead>(path(companyId, 'stock-movements'), body),
        ),
      ),
    );
  }

  /**
   * One delivery shared over several places, all stored or none: the ids of the movements written, one per place, in
   * the order given. 422 naming the field refused, a place the company does not have or one named twice.
   */
  async receiveSplit(companyId: string, input: StockReceiptInput): Promise<string[]> {
    const body: StockReceiptStockReceiptWrite = {
      ...input,
      parts: input.parts.map((part) => ({ ...part })),
    };
    return this.guard(async () => {
      const receipt = await firstValueFrom(
        this.http.post<StockReceiptStockReceiptRead>(path(companyId, 'stock-receipts'), body),
      );
      return receipt.movementIds ?? [];
    });
  }

  /**
   * What was found at several places, all stored or none: the ids of the counts written, one per place, in the order
   * given. 422 naming the field refused, a place the company does not have, one named twice or a count below nothing.
   */
  async countSplit(companyId: string, input: StockCountInput): Promise<string[]> {
    const body: StockCountStockCountWrite = {
      ...input,
      parts: input.parts.map((part) => ({ ...part })),
    };
    return this.guard(async () => {
      const count = await firstValueFrom(
        this.http.post<StockCountStockCountRead>(path(companyId, 'stock-counts'), body),
      );
      return count.movementIds ?? [];
    });
  }

  /**
   * The cost of a receipt left « à compléter », entered by a cost reader (docs/SPEC.md § 7, audit 2026-10-06 C
   * challenge 9); a receipt whose cost is already known answers `cost_known`.
   */
  async enterReceiptCost(
    companyId: string,
    movementId: string,
    unitCost: string,
    applyCost: CostBasis | null,
  ): Promise<StockMovementRow> {
    const body: ReceiptCostEntryWrite = { unitCost, ...(applyCost === null ? {} : { applyCost }) };
    return this.guard(
      async () =>
        toMovement(
          await firstValueFrom(
            this.http.post<StockMovementStockMovementRead>(
              `${path(companyId, 'stock-movements', movementId)}/cost`,
              body,
            ),
          ),
        ),
      'cost_known',
    );
  }

  private async guard<T>(call: () => Promise<T>, conflict: InventoryError = 'invalid'): Promise<T> {
    try {
      return await call();
    } catch (error) {
      throw new InventoryRefused(codeOf(error, conflict));
    }
  }
}

function codeOf(error: unknown, conflict: InventoryError): InventoryError {
  if (!(error instanceof HttpErrorResponse) || error.status === 0) {
    return 'network';
  }
  switch (error.status) {
    case 404:
      return 'not_found';
    case 409:
      return conflict;
    default:
      return 'invalid';
  }
}

/** What a floor shows a plan image at when it says nothing else, matching the API's own default. */
const DEFAULT_PLAN_OPACITY = 35;

const path = (companyId: string, collection: string, id?: string): string =>
  `/api/companies/${encodeURIComponent(companyId)}/${collection}${id === undefined ? '' : `/${encodeURIComponent(id)}`}`;

function toOptions(raw: StockOptionsStockOptionsRead): StockOptions {
  return {
    establishments: (raw.establishments ?? []).map((establishment) => ({ ...establishment })),
    // Metres as numbers, as every other measurement on the plan is: the API holds them as decimal strings.
    planShapes: (raw.planShapes ?? []).map((shape) => ({
      shape: shape.shape,
      width: Number(shape.width),
      depth: Number(shape.depth),
    })),
    // The structure tools carry a height as well, which the palette's shapes have none of: a wall's height is the
    // whole building's until the company says otherwise, while a rack's is a fact somebody measured about it.
    structureShapes: (raw.structureShapes ?? []).map((shape) => ({
      kind: shape.kind as StructureKind,
      width: Number(shape.width),
      depth: Number(shape.depth),
      height: Number(shape.height),
    })),
  };
}

/** Only what the search asks for: an absent parameter is the API's own default, never an empty one. */
function toSearchParams(search: StockSearch): HttpParams {
  let params = new HttpParams().set('page', search.page).set('itemsPerPage', search.itemsPerPage);
  if (search.q.trim() !== '') params = params.set('q', search.q.trim());
  for (const id of search.locationIds) params = params.append('locationId[]', id);
  for (const id of search.establishmentIds) params = params.append('establishmentId[]', id);
  for (const id of search.productIds) params = params.append('productId[]', id);
  if (search.negative !== null) params = params.set('negative', search.negative);
  if (search.expired !== null) params = params.set('expired', search.expired);
  for (const [key, value] of Object.entries(search.intervals)) {
    params = params.set(apiRangeKey(key), value);
  }
  if (search.order !== null)
    params = params.set(`order[${search.order.key}]`, search.order.direction);
  return params;
}

/** Only what the search asks for: an absent parameter is the API's own default, never an empty one. */
function toMovementParams(search: StockMovementSearch): HttpParams {
  let params = new HttpParams().set('page', search.page).set('itemsPerPage', search.itemsPerPage);
  if (search.q.trim() !== '') params = params.set('q', search.q.trim());
  for (const id of search.productIds) params = params.append('productId[]', id);
  for (const id of search.locationIds) params = params.append('locationId[]', id);
  for (const kind of search.kinds) params = params.append('kind[]', kind);
  for (const type of search.sourceTypes) params = params.append('sourceType[]', type);
  for (const reason of search.reasons) params = params.append('reason[]', reason);
  if (search.costToComplete !== null) params = params.set('costToComplete', search.costToComplete);
  for (const [key, value] of Object.entries(search.intervals)) {
    params = params.set(apiRangeKey(key), value);
  }
  if (search.lot !== null && search.lot.trim() !== '')
    params = params.set('lot', search.lot.trim());
  if (search.order !== null)
    params = params.set(`order[${search.order.key}]`, search.order.direction);
  return params;
}

function toLevel(raw: StockLevelJsonldStockLevelRead): StockLevelRow {
  return {
    id: raw.id ?? '',
    productId: raw.productId ?? '',
    productReference: raw.productReference ?? '',
    productName: raw.productName ?? '',
    unitCode: raw.unitCode ?? '',
    unitName: raw.unitName ?? '',
    unitDecimals: raw.unitDecimals ?? API_DECIMALS,
    locationId: raw.locationId ?? '',
    locationCode: raw.locationCode ?? '',
    locationName: raw.locationName ?? '',
    establishmentId: raw.establishmentId ?? '',
    quantity: raw.quantity ?? '0.000',
    lotId: raw.lotId ?? null,
    lotCode: raw.lotCode ?? null,
    lotExpiresOn: raw.lotExpiresOn ?? null,
    lotReleased: raw.lotReleased ?? false,
    mainPhotoId: raw.mainPhotoId ?? null,
  };
}

/** A revision keeps the establishment it was created under; the API ignores the field, the generated type wants it. */
function floorBody(input: StockFloorInput): StockFloorStockFloorWrite {
  return { ...input };
}

function drawingBody(input: StockDrawingInput): StockDrawingStockDrawingWrite {
  return { ...input };
}

function toFloor(raw: StockFloorStockFloorRead): StockFloorRow {
  return {
    id: raw.id ?? '',
    establishmentId: raw.establishmentId ?? '',
    name: raw.name ?? '',
    level: raw.level ?? 0,
    widthMetres: raw.widthMetres ?? null,
    depthMetres: raw.depthMetres ?? null,
    imageFileId: raw.imageFileId ?? null,
    imageMetresWide: raw.imageMetresWide ?? null,
    imageOpacity: raw.imageOpacity ?? DEFAULT_PLAN_OPACITY,
    drawingCount: raw.drawingCount ?? 0,
  };
}

function structureBody(input: StockStructureInput): StockStructureStockStructureWrite {
  return { ...input };
}

/**
 * A piece of the building, as the screen works in it. An unknown kind falls back to `wall`, the only one that is
 * always drawable: a piece the screen could not name would otherwise disappear off a plan it is really on.
 */
function toStructure(raw: StockStructureStockStructureRead): StockStructureRow {
  return {
    id: raw.id ?? '',
    floorId: raw.floorId ?? '',
    kind: STRUCTURE_KINDS.find((kind) => kind === raw.kind) ?? 'wall',
    name: raw.name ?? '',
    x: raw.x ?? '0.000',
    y: raw.y ?? '0.000',
    width: raw.width ?? '0.000',
    depth: raw.depth ?? '0.000',
    rotation: raw.rotation ?? 0,
    height: raw.height ?? '0.000',
  };
}

function toDrawing(raw: StockDrawingStockDrawingRead): StockDrawingRow {
  return {
    id: raw.id ?? '',
    floorId: raw.floorId ?? '',
    locationId: raw.locationId ?? '',
    locationCode: raw.locationCode ?? '',
    locationName: raw.locationName ?? '',
    locationKind: STOCK_LOCATION_KINDS.find((kind) => kind === raw.locationKind) ?? 'zone',
    x: raw.x ?? '0.000',
    y: raw.y ?? '0.000',
    width: raw.width ?? '0.000',
    depth: raw.depth ?? '0.000',
    rotation: raw.rotation ?? 0,
    height: raw.height ?? '0.000',
  };
}

function toLocation(raw: StockLocationStockLocationRead): StockLocationRow {
  return {
    id: raw.id ?? '',
    establishmentId: raw.establishmentId ?? '',
    parentId: raw.parentId ?? null,
    kind: STOCK_LOCATION_KINDS.find((kind) => kind === raw.kind) ?? 'zone',
    code: raw.code ?? '',
    name: raw.name ?? '',
    isDefault: raw.isDefault ?? false,
    childCount: raw.childCount ?? 0,
    movementCount: raw.movementCount ?? 0,
  };
}

function toMovement(
  raw: StockMovementStockMovementRead | StockMovementJsonldStockMovementRead,
): StockMovementRow {
  return {
    id: raw.id ?? '',
    productId: raw.productId ?? '',
    productReference: raw.productReference ?? '',
    productName: raw.productName ?? '',
    unitCode: raw.unitCode ?? '',
    unitDecimals: raw.unitDecimals ?? API_DECIMALS,
    locationId: raw.locationId ?? '',
    locationCode: raw.locationCode ?? '',
    locationName: raw.locationName ?? '',
    kind: STOCK_MOVEMENT_KINDS.find((kind) => kind === raw.kind) ?? 'adjustment',
    quantity: raw.quantity ?? '0.000',
    sourceType: STOCK_SOURCE_TYPES.find((type) => type === raw.sourceType) ?? 'receipt',
    sourceId: raw.sourceId ?? null,
    lotCode: raw.lotCode ?? null,
    reason: STOCK_LOSS_REASONS.find((reason) => reason === raw.reason) ?? null,
    note: raw.note ?? null,
    recordedBy: raw.recordedBy ?? null,
    vendorId: raw.vendorId ?? null,
    vendorName: raw.vendorName ?? null,
    supplierReference: raw.supplierReference ?? null,
    receivedOn: raw.receivedOn ?? null,
    at: raw.at ?? '',
    costTyped: raw.costTyped ?? false,
    costToComplete: raw.costToComplete ?? false,
  };
}
