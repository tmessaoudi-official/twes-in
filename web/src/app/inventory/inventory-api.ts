// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  ApiCompaniesCompanyIdstockLevelsGetCollectionResponse,
  ApiCompaniesCompanyIdstockMovementsGetCollectionResponse,
  StockLevelJsonldStockLevelRead,
  StockMovementJsonldStockMovementRead,
  StockLocationStockLocationRead,
  StockLocationStockLocationWrite,
  StockMovementStockMovementRead,
  StockMovementStockMovementWrite,
  StockOptionsStockOptionsRead,
  StockProductPickStockProductPickRead,
} from '../api/types.gen';
import type { ListPage } from '../shared/list/list-types';
import { type PickAsked, pickParams } from '../shared/form/pick-api';
import {
  type InventoryError,
  STOCK_LOCATION_KINDS,
  STOCK_MOVEMENT_KINDS,
  STOCK_SOURCE_TYPES,
  type StockLevelRow,
  type StockLocationInput,
  type StockLocationRow,
  type StockMovementInput,
  type StockMovementRow,
  API_DECIMALS,
  type StockOptions,
  type StockProductOption,
  type StockMovementSearch,
  type StockSearch,
} from './inventory-types';

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
      }));
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

const path = (companyId: string, collection: string, id?: string): string =>
  `/api/companies/${encodeURIComponent(companyId)}/${collection}${id === undefined ? '' : `/${encodeURIComponent(id)}`}`;

function toOptions(raw: StockOptionsStockOptionsRead): StockOptions {
  return {
    establishments: (raw.establishments ?? []).map((establishment) => ({ ...establishment })),
  };
}

/** Only what the search asks for: an absent parameter is the API's own default, never an empty one. */
function toSearchParams(search: StockSearch): HttpParams {
  let params = new HttpParams().set('page', search.page).set('itemsPerPage', search.itemsPerPage);
  if (search.q.trim() !== '') params = params.set('q', search.q.trim());
  if (search.locationId !== null) params = params.set('locationId', search.locationId);
  if (search.establishmentId !== null)
    params = params.set('establishmentId', search.establishmentId);
  if (search.order !== null)
    params = params.set(`order[${search.order.key}]`, search.order.direction);
  return params;
}

/** Only what the search asks for: an absent parameter is the API's own default, never an empty one. */
function toMovementParams(search: StockMovementSearch): HttpParams {
  let params = new HttpParams().set('page', search.page).set('itemsPerPage', search.itemsPerPage);
  if (search.q.trim() !== '') params = params.set('q', search.q.trim());
  if (search.productId !== null) params = params.set('productId', search.productId);
  if (search.locationId !== null) params = params.set('locationId', search.locationId);
  if (search.kind !== null) params = params.set('kind', search.kind);
  if (search.sourceType !== null) params = params.set('sourceType', search.sourceType);
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
    unitDecimals: raw.unitDecimals ?? API_DECIMALS,
    locationId: raw.locationId ?? '',
    locationCode: raw.locationCode ?? '',
    locationName: raw.locationName ?? '',
    establishmentId: raw.establishmentId ?? '',
    quantity: raw.quantity ?? '0.000',
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
    recordedBy: raw.recordedBy ?? null,
    at: raw.at ?? '',
  };
}
