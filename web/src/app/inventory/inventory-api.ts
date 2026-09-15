// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  StockLevelStockLevelRead,
  StockLocationStockLocationRead,
  StockLocationStockLocationWrite,
  StockMovementStockMovementRead,
  StockMovementStockMovementWrite,
  StockOptionsStockOptionsRead,
} from '../api/types.gen';
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
  type StockOptions,
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

  async levels(companyId: string): Promise<StockLevelRow[]> {
    return this.guard(async () =>
      (
        await firstValueFrom(
          this.http.get<StockLevelStockLevelRead[]>(path(companyId, 'stock-levels')),
        )
      ).map(toLevel),
    );
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

  /** One product's movements, or the company's latest when no product is named; newest first. */
  async movements(companyId: string, productId: string | null): Promise<StockMovementRow[]> {
    const params = productId === null ? undefined : new HttpParams().set('productId', productId);
    return this.guard(async () =>
      (
        await firstValueFrom(
          this.http.get<StockMovementStockMovementRead[]>(path(companyId, 'stock-movements'), {
            params,
          }),
        )
      ).map(toMovement),
    );
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
    products: (raw.products ?? []).map((product) => ({ ...product })),
    establishments: (raw.establishments ?? []).map((establishment) => ({ ...establishment })),
  };
}

function toLevel(raw: StockLevelStockLevelRead): StockLevelRow {
  return {
    productId: raw.productId ?? '',
    productReference: raw.productReference ?? '',
    productName: raw.productName ?? '',
    unitCode: raw.unitCode ?? '',
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

function toMovement(raw: StockMovementStockMovementRead): StockMovementRow {
  return {
    id: raw.id ?? '',
    productId: raw.productId ?? '',
    locationId: raw.locationId ?? '',
    kind: STOCK_MOVEMENT_KINDS.find((kind) => kind === raw.kind) ?? 'adjustment',
    quantity: raw.quantity ?? '0.000',
    sourceType: STOCK_SOURCE_TYPES.find((type) => type === raw.sourceType) ?? 'receipt',
    sourceId: raw.sourceId ?? null,
    recordedBy: raw.recordedBy ?? null,
    at: raw.at ?? '',
  };
}
